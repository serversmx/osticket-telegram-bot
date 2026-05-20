<?php
/**
 * PII redaction for log context arrays.
 *
 * Phones are masked to last-4-digits, message bodies become "[N chars]
 * preview…", and `apikey` / `api_key` / `authorization` keys are replaced
 * with `[REDACTED]`.
 *
 * Extracted into its own class so it can be unit-tested without booting
 * osTicket. The main plugin class delegates to `EvoLogRedactor::context()`.
 *
 * @license GPL-2.0-or-later
 */
class EvoLogRedactor {

    /** Keys whose values look like phone numbers OR Telegram chat IDs. */
    private static $PHONE_KEYS = array('phone', 'number', 'numbers', 'mentioned', 'chat_id', 'chat_ids');
    /** Keys whose values look like message bodies. */
    private static $BODY_KEYS = array('text', 'message', 'body');
    /** Keys whose values are credentials. */
    private static $SECRET_KEYS = array(
        'apikey', 'api_key', 'authorization', 'token',
        'bot_token', 'secret_token', 'webhook_secret_token',
    );

    /**
     * Walk an arbitrary structure and redact sensitive data by key.
     * Non-array values pass through unchanged.
     */
    public static function context($value) {
        if (!is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $k => $v) {
            $kl = is_string($k) ? strtolower($k) : null;
            if (in_array($kl, self::$PHONE_KEYS, true)) {
                $out[$k] = self::maskPhone($v);
            } elseif (in_array($kl, self::$BODY_KEYS, true)) {
                $out[$k] = self::previewText($v);
            } elseif (in_array($kl, self::$SECRET_KEYS, true)) {
                $out[$k] = '[REDACTED]';
            } else {
                $out[$k] = self::context($v);
            }
        }
        return $out;
    }

    /**
     * Phone → last-4-digits with `*` padding. Recursive over arrays.
     */
    public static function maskPhone($v) {
        if (is_array($v)) {
            $out = array();
            foreach ($v as $item) { $out[] = self::maskPhone($item); }
            return $out;
        }
        $s = (string) $v;
        $len = strlen($s);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($s, -4);
    }

    /**
     * Message body → "[N chars] first-40-chars…", collapses whitespace.
     */
    public static function previewText($v) {
        if (!is_scalar($v)) {
            return '[non-scalar]';
        }
        $s = (string) $v;
        $len = strlen($s);
        $head = preg_replace('/\s+/', ' ', substr($s, 0, 40));
        if ($len <= 40) {
            return '[' . $len . ' chars] ' . $head;
        }
        return '[' . $len . ' chars] ' . $head . '…';
    }
}
