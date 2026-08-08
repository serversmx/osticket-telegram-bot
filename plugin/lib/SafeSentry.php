<?php
/**
 * TgSafeSentry — enforces PII masking for Telegram Bot Sentry telemetry.
 *
 * The pre-existing TgLogRedactor scrubs context arrays by KEY name (any
 * key matching PHONE_KEYS is masked). That works for structured context
 * but has TWO gaps identified in the 2026-08-08 review:
 *
 *  1. Sentry TAGS are separate from context. Sentry indexes tags for
 *     search — a raw supergroup id like -1003999130791 ends up
 *     permanently searchable in the Sentry UI if it slips into a tag.
 *
 *  2. If a caller accidentally passes ['chat' => $chatId] instead of
 *     ['chat_id' => $chatId], the wrong key doesn't match PHONE_KEYS
 *     and the value passes through unmasked.
 *
 * This helper takes the value-first approach: always call maskChatId()
 * on a chat id BEFORE putting it anywhere near Sentry (tag OR context).
 * Also provides captureWarn() / captureError() helpers that route
 * through the existing SentryReporter with pre-masked payloads.
 *
 * @license GPL-2.0-or-later
 */

class TgSafeSentry {

    /** @var object The plugin's SentryReporter instance (or null if disabled). */
    private $sentry;

    public function __construct($sentryReporter = null) {
        $this->sentry = is_object($sentryReporter) ? $sentryReporter : null;
    }

    /**
     * Reduce a chat id to a non-identifying identifier suitable for logs
     * and Sentry tags. Preserves enough entropy to distinguish groups
     * without leaking the full id.
     *
     * Format: "***4650" (last 4 digits, positive or negative sign dropped).
     * A supergroup id -1003999130791 becomes "***0791".
     */
    public static function maskChatId($chatId) {
        $digits = preg_replace('/\D/', '', (string) $chatId);
        if ($digits === '') { return '***'; }
        $tail = substr($digits, -4);
        return '***' . str_pad($tail, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Same idea for message_id — Telegram-side identifier, low PII risk
     * on its own but combined with chat_id it could aid correlation.
     */
    public static function maskMessageId($messageId) {
        $s = (string) (int) $messageId;
        if (strlen($s) <= 4) { return $s; }
        return substr($s, 0, 2) . '..' . substr($s, -2);
    }

    /**
     * Build a Sentry-safe tags array. All chat / message identifiers are
     * pre-masked. Ticket ids are considered internal (fine to log raw).
     */
    public static function tagsFor(array $meta) {
        $tags = array();
        if (isset($meta['ticket_id'])) { $tags['ticket_id'] = (string) (int) $meta['ticket_id']; }
        if (isset($meta['notif_type']))  { $tags['notif_type']  = (string) $meta['notif_type']; }
        if (isset($meta['error_kind']))  { $tags['error_kind']  = (string) $meta['error_kind']; }
        if (isset($meta['chat_id']))     { $tags['chat_last4']  = self::maskChatId($meta['chat_id']); }
        if (isset($meta['message_id']))  { $tags['message']     = self::maskMessageId($meta['message_id']); }
        return $tags;
    }

    /**
     * Sanitize an arbitrary context array: replace any chat_id-ish value
     * with the masked form regardless of the key name. Complements (does
     * NOT replace) TgLogRedactor, which does the same by key name.
     */
    public static function sanitizeContext(array $ctx) {
        $out = array();
        foreach ($ctx as $k => $v) {
            $keyLower = strtolower((string) $k);
            if (in_array($keyLower, array('chat', 'chatid', 'chat_id', 'chats', 'chat_ids'), true)) {
                $out[$k] = is_array($v)
                    ? array_map(array(__CLASS__, 'maskChatId'), $v)
                    : self::maskChatId($v);
            } elseif (in_array($keyLower, array('message_id', 'messageid', 'msg_id'), true)) {
                $out[$k] = self::maskMessageId($v);
            } elseif (is_array($v)) {
                $out[$k] = self::sanitizeContext($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Log a warning with pre-masked payload. Safe to call even if the
     * Sentry reporter is disabled (falls back to error_log).
     */
    public function warn($message, array $meta = array()) {
        $tags = self::tagsFor($meta);
        $ctx  = self::sanitizeContext($meta);
        if ($this->sentry && method_exists($this->sentry, 'captureMessage')) {
            try {
                $this->sentry->captureMessage($message, 'warning', array('tags' => $tags, 'extra' => $ctx));
                return;
            } catch (Exception $e) { /* fall through */ }
        }
        error_log('[TgSafeSentry.warn] ' . $message . ' ' . json_encode($ctx));
    }

    public function error($message, array $meta = array()) {
        $tags = self::tagsFor($meta);
        $ctx  = self::sanitizeContext($meta);
        if ($this->sentry && method_exists($this->sentry, 'captureMessage')) {
            try {
                $this->sentry->captureMessage($message, 'error', array('tags' => $tags, 'extra' => $ctx));
                return;
            } catch (Exception $e) { /* fall through */ }
        }
        error_log('[TgSafeSentry.error] ' . $message . ' ' . json_encode($ctx));
    }
}
