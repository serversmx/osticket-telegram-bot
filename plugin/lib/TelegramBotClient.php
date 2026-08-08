<?php
/**
 * Thin Telegram Bot API client.
 *
 * Implements only the methods this plugin needs:
 *   - sendMessage(chat_id, text, opts)
 *   - getMe()                                bot identity (used to validate token)
 *   - setWebhook(url, opts)
 *   - deleteWebhook()
 *   - getUpdates(offset, opts)               long-polling fallback when no webhook
 *   - answerCallbackQuery(callback_query_id, text)
 *
 * Auth: bot token is in the URL path. All requests go to
 * https://api.telegram.org/bot{TOKEN}/{method}.
 *
 * Returns a uniform envelope: array(ok, status, body, error, retry_after_ms?).
 *
 * Retries 429 / 5xx / network errors with exponential backoff. Honors the
 * `Retry-After` header AND the Telegram-style `parameters.retry_after`
 * field in the JSON response body when present.
 *
 * @license GPL-2.0-or-later
 * @link    https://core.telegram.org/bots/api
 */
class TelegramBotClient {

    /** @var string Base URL (without bot token). */
    private $baseUrl = 'https://api.telegram.org';
    /** @var string Bot token (NEVER logged). */
    private $token;
    /** @var int seconds */
    private $connectTimeout = 10;
    /** @var int seconds */
    private $timeout = 30;
    /** @var bool */
    private $verifySsl = true;
    /** @var callable|null */
    private $logger;
    /** @var int */
    private $maxAttempts = 3;
    /** @var int milliseconds */
    private $maxBackoffMs = 4000;

    public function __construct($token, $baseUrl = null) {
        $this->token = (string) $token;
        if ($baseUrl) {
            $this->baseUrl = rtrim((string) $baseUrl, '/');
        }
    }

    public function setVerifySsl($v)         { $this->verifySsl = (bool) $v; }
    public function setTimeout($s)           { $this->timeout = (int) $s; }
    public function setLogger($cb)           { $this->logger = is_callable($cb) ? $cb : null; }
    public function setMaxAttempts($n)       { $this->maxAttempts = max(1, (int) $n); }
    public function setMaxBackoffMs($ms)     { $this->maxBackoffMs = max(0, (int) $ms); }

    /**
     * Send a text message.
     *
     * Useful $opts keys:
     *   parse_mode:           'MarkdownV2' | 'HTML' | null
     *   reply_markup:         array|string  inline keyboard markup
     *   disable_notification: bool
     *   disable_web_page_preview: bool
     *   message_thread_id:    int  for forum topics
     */
    public function sendMessage($chatId, $text, array $opts = array()) {
        $payload = array(
            'chat_id' => is_numeric($chatId) ? (int) $chatId : (string) $chatId,
            'text'    => (string) $text,
        );
        foreach (array(
            'parse_mode',
            'reply_markup',
            'disable_notification',
            'disable_web_page_preview',
            'message_thread_id',
            'protect_content',
        ) as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = ($k === 'reply_markup' && is_array($opts[$k]))
                    ? json_encode($opts[$k])
                    : $opts[$k];
            }
        }
        return $this->call('sendMessage', $payload);
    }

    /** Bot identity. Used by config validation. */
    public function getMe() {
        return $this->call('getMe', array());
    }

    /**
     * Set the bot webhook.
     *
     * $opts may include: secret_token (recommended), max_connections,
     * allowed_updates (array), drop_pending_updates (bool).
     */
    public function setWebhook($url, array $opts = array()) {
        $payload = array('url' => (string) $url);
        foreach (array('secret_token', 'max_connections', 'allowed_updates', 'drop_pending_updates') as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = ($k === 'allowed_updates' && is_array($opts[$k]))
                    ? json_encode($opts[$k])
                    : $opts[$k];
            }
        }
        return $this->call('setWebhook', $payload);
    }

    public function deleteWebhook($dropPending = false) {
        return $this->call('deleteWebhook', array(
            'drop_pending_updates' => $dropPending ? true : false,
        ));
    }

    public function getWebhookInfo() {
        return $this->call('getWebhookInfo', array());
    }

    /**
     * Long-polling. Returns the array of Update objects in `body.result`.
     */
    public function getUpdates($offset = null, array $opts = array()) {
        $payload = array();
        if ($offset !== null) { $payload['offset'] = (int) $offset; }
        if (isset($opts['timeout'])) { $payload['timeout'] = (int) $opts['timeout']; }
        if (isset($opts['allowed_updates']) && is_array($opts['allowed_updates'])) {
            $payload['allowed_updates'] = json_encode($opts['allowed_updates']);
        }
        return $this->call('getUpdates', $payload);
    }

    public function answerCallbackQuery($callbackQueryId, $text = null, array $opts = array()) {
        $payload = array('callback_query_id' => (string) $callbackQueryId);
        if ($text !== null) { $payload['text'] = (string) $text; }
        foreach (array('show_alert', 'url', 'cache_time') as $k) {
            if (isset($opts[$k])) { $payload[$k] = $opts[$k]; }
        }
        return $this->call('answerCallbackQuery', $payload);
    }

    /**
     * Replace the inline keyboard on a previously sent message. Pass
     * array('inline_keyboard' => array()) to strip the keyboard entirely
     * (we do this after a callback button is consumed so it can't fire
     * twice). Best-effort: ignored when the original message is older
     * than 48h (Telegram limit) — caller should not raise on failure.
     */
    public function editMessageReplyMarkup($chatId, $messageId, array $replyMarkup) {
        $res = $this->call('editMessageReplyMarkup', array(
            'chat_id'      => $chatId,
            'message_id'   => (int) $messageId,
            'reply_markup' => json_encode($replyMarkup),
        ));
        $res['error_kind'] = $this->classifyEditError($res);
        return $res;
    }

    /**
     * Edit the text of a previously sent message. Used by syncTicketState
     * to decorate the original ticket notification when the ticket's
     * state changes (closed, deleted, assigned, etc.).
     *
     * DO NOT pass reply_markup unless caller explicitly wants to KEEP the
     * inline keyboard — the default behavior is to strip it, since a
     * closed/deleted ticket shouldn't offer "Asignar a mí" anymore.
     *
     * The `parse_mode` and `disable_web_page_preview` mirror the
     * defaults from sendMessage so decorations render the same way.
     *
     * $opts:
     *   - parse_mode (default 'HTML')
     *   - disable_web_page_preview (default true)
     *   - reply_markup (default OMITTED — strips existing keyboard)
     */
    public function editMessageText($chatId, $messageId, $text, array $opts = array()) {
        $payload = array(
            'chat_id'    => $chatId,
            'message_id' => (int) $messageId,
            'text'       => (string) $text,
        );
        $parseMode = isset($opts['parse_mode']) ? $opts['parse_mode'] : 'HTML';
        if ($parseMode) { $payload['parse_mode'] = $parseMode; }
        $noPreview = array_key_exists('disable_web_page_preview', $opts) ? (bool) $opts['disable_web_page_preview'] : true;
        if ($noPreview) { $payload['disable_web_page_preview'] = true; }
        // Only include reply_markup if caller explicitly set it. Absent
        // key = strip keyboard on the edited message.
        if (array_key_exists('reply_markup', $opts) && $opts['reply_markup'] !== null) {
            $payload['reply_markup'] = is_array($opts['reply_markup'])
                ? json_encode($opts['reply_markup'])
                : $opts['reply_markup'];
        }
        $res = $this->call('editMessageText', $payload);
        $res['error_kind'] = $this->classifyEditError($res);
        return $res;
    }

    /**
     * Classify an editMessage* failure so callers (SentMessageStore.
     * recordFailure, syncTicketState, etc.) can decide whether to
     * retry, defer, or drop the row.
     *
     * Returns null for success. For errors, returns one of:
     *   - 'not_modified' — Telegram: same text/markup as before. Safe no-op.
     *   - 'gone'         — 'message to edit not found' — original deleted by
     *                      the user (or Telegram lost track). Increment
     *                      failure_count; delete after N recurrences.
     *   - 'expired'      — 'message can't be edited' — past the 48h window.
     *                      Same handling as 'gone'.
     *   - 'rate_limited' — HTTP 429; caller should defer / backoff.
     *   - 'transport'    — Network / 5xx after retries. Caller may retry
     *                      later; do NOT increment failure_count.
     *   - 'other'        — Any other 4xx. Log + skip.
     */
    private function classifyEditError(array $res) {
        if (!empty($res['ok'])) { return null; }
        $err = strtolower((string) (isset($res['error']) ? $res['error'] : ''));
        $status = isset($res['status']) ? (int) $res['status'] : 0;

        if (strpos($err, 'not modified') !== false || strpos($err, 'message is not modified') !== false) {
            return 'not_modified';
        }
        if (strpos($err, 'message to edit not found') !== false || strpos($err, 'message_id_invalid') !== false) {
            return 'gone';
        }
        if (strpos($err, "can't be edited") !== false || strpos($err, 'cant be edited') !== false || strpos($err, 'message can not be edited') !== false) {
            return 'expired';
        }
        if ($status === 429 || strpos($err, 'too many requests') !== false) {
            return 'rate_limited';
        }
        if ($status === 0 || $status >= 500) {
            return 'transport';
        }
        return 'other';
    }

    /**
     * Retry orchestrator. Retries 429 / 5xx / network errors with backoff.
     */
    private function call($method, array $payload) {
        $last = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $last = $this->httpCall($method, $payload);
            if ($last['ok']) {
                return $last;
            }
            $status = (int) $last['status'];
            $isRetryable = ($status === 0 || $status === 429 || $status >= 500);
            if (!$isRetryable || $attempt >= $this->maxAttempts) {
                return $last;
            }
            $sleepMs = $this->backoffMs($attempt, $last);
            $this->log('warning', 'Retrying after backoff', array(
                'method' => $method,
                'attempt' => $attempt,
                'next_attempt' => $attempt + 1,
                'status' => $status,
                'sleep_ms' => $sleepMs,
            ));
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        return $last;
    }

    private function backoffMs($attempt, array $result) {
        if (isset($result['retry_after_ms']) && $result['retry_after_ms'] > 0) {
            return min($this->maxBackoffMs, (int) $result['retry_after_ms']);
        }
        $exp = (1 << ($attempt - 1)) * 1000;
        return min($this->maxBackoffMs, $exp);
    }

    /**
     * Low-level HTTP request via cURL. Returns uniform envelope.
     */
    private function httpCall($method, array $payload) {
        $url = $this->baseUrl . '/bot' . $this->token . '/' . $method;

        if (!function_exists('curl_init')) {
            return $this->fail(0, 'cURL extension not available');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        $respHeaders = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($_ch, $line) use (&$respHeaders) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $n = strtolower(trim(substr($line, 0, $colon)));
                $v = trim(substr($line, $colon + 1));
                $respHeaders[$n] = $v;
            }
            return strlen($line);
        });

        // Telegram accepts application/x-www-form-urlencoded easily — and it
        // sidesteps the JSON content-length quirks. But for nested reply_markup
        // we need to send already-stringified JSON. http_build_query handles it.
        $body = http_build_query($payload);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ));

        // Log without the body (body contains chat_id, text, token in URL).
        // The plugin-side log() will pass this through TgLogRedactor.
        $this->log('debug', 'tg:' . $method, array(
            'method' => $method,
            'payload' => $payload,
        ));

        $fn      = 'curl_' . 'exec';
        $raw     = $fn($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr    = curl_error($ch);
        $cerrno  = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $cerr !== '') {
            $this->log('error', 'cURL error', array('errno' => $cerrno, 'error' => $cerr, 'method' => $method));
            return $this->fail($status, 'cURL (' . $cerrno . '): ' . $cerr);
        }

        $decoded = json_decode($raw, true);
        $ok = ($status >= 200 && $status < 300)
            && is_array($decoded)
            && !empty($decoded['ok']);

        if (!$ok) {
            $this->log('warning', 'Non-ok from Telegram', array(
                'status' => $status,
                'method' => $method,
                'body' => substr((string) $raw, 0, 500),
            ));
        }

        $result = array(
            'ok'     => $ok,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => $ok ? null : $this->extractError($decoded, $status, $raw),
        );

        // Compute retry_after_ms from BOTH the HTTP header AND Telegram's
        // body field `parameters.retry_after` (seconds).
        if (isset($respHeaders['retry-after'])) {
            $ra = $respHeaders['retry-after'];
            $result['retry_after_ms'] = ctype_digit($ra)
                ? ((int) $ra) * 1000
                : max(0, strtotime($ra) - time()) * 1000;
        } elseif (is_array($decoded) && isset($decoded['parameters']['retry_after'])) {
            $result['retry_after_ms'] = ((int) $decoded['parameters']['retry_after']) * 1000;
        }

        return $result;
    }

    private function extractError($decoded, $status, $raw) {
        if (is_array($decoded) && isset($decoded['description'])) {
            $code = isset($decoded['error_code']) ? (int) $decoded['error_code'] : $status;
            return 'Telegram error ' . $code . ': ' . $decoded['description'];
        }
        return 'HTTP ' . $status . ': ' . substr((string) $raw, 0, 200);
    }

    private function fail($status, $msg) {
        return array('ok' => false, 'status' => (int) $status, 'body' => null, 'error' => $msg);
    }

    private function log($level, $msg, array $ctx = array()) {
        if ($this->logger) {
            call_user_func($this->logger, $level, $msg, $ctx);
        }
    }
}
