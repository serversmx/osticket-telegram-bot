<?php
/**
 * TgRateLimiter — persistent token bucket for Telegram Bot API calls,
 * shared across concurrent PHP-FPM workers.
 *
 * Telegram's server-side ceilings (docs):
 *   • Bot-wide:    ~30 msg/sec across all chats
 *   • Per chat:    ~1 msg/sec, ~20/min in the same channel/group
 *
 * The 50ms sleep from design v1 only throttled a single syncTicketState
 * invocation. Under bulk operations (mass close from queue UI, timer
 * sweep, callback storm) N concurrent invocations blew right past
 * Telegram's ceiling. This limiter is CROSS-INVOCATION: two concurrent
 * requests share the same buckets via DB rows.
 *
 * Bucket storage: a `<prefix>telegram_rate_bucket` table with one row
 * per key. We keep it simple — an "AT ... TOKENS X" pair updated
 * atomically via a conditional UPDATE. If we can't acquire a token, we
 * sleep briefly and retry a bounded number of times, otherwise we
 * report throttled=true and the caller decides to defer/queue.
 *
 * The store is tiny (< 1KB per active chat + 1 row for the global
 * bucket) and self-heals: rows past 24h of inactivity are purged by
 * the same cron that purges the sent-messages table.
 *
 * @license GPL-2.0-or-later
 */

class TgRateLimiter {

    // Refill: tokens/second. Global slightly conservative (25 vs 30) to
    // leave headroom for non-syncTicketState sends (webhook /help etc.)
    const GLOBAL_TOKENS_PER_SEC = 25.0;
    const GLOBAL_CAPACITY       = 25.0;

    const CHAT_TOKENS_PER_SEC   = 1.0;
    const CHAT_CAPACITY         = 3.0;   // small burst allowance

    // How long we're willing to sleep in-request waiting for a token.
    // syncTicketState calls this per-message; we want short waits inline
    // and defer the rest.
    const MAX_INLINE_WAIT_MS    = 500;
    const RETRY_INTERVAL_MS     = 50;

    private $table;
    private $schemaReady = false;

    public function __construct($tablePrefix = null) {
        $prefix = $tablePrefix !== null ? $tablePrefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_');
        $this->table = $prefix . 'telegram_rate_bucket';
    }

    /**
     * Try to acquire one token from BOTH the global bucket and the
     * per-chat bucket. Returns true on success (safe to call the API),
     * false if we couldn't get a token within MAX_INLINE_WAIT_MS (caller
     * should defer the call to the cron worker).
     *
     * $chatId can be 0 to skip the per-chat check (rare — /help, getMe).
     */
    public function tryAcquire($chatId = 0) {
        $deadline = $this->nowMs() + self::MAX_INLINE_WAIT_MS;
        while (true) {
            $global = $this->consume('__global__', self::GLOBAL_CAPACITY, self::GLOBAL_TOKENS_PER_SEC);
            if ($global && $chatId) {
                $chat = $this->consume('chat:' . (string) $chatId, self::CHAT_CAPACITY, self::CHAT_TOKENS_PER_SEC);
                if ($chat) { return true; }
                // We consumed a global token but couldn't get a chat
                // token. That's OK — global will refill in ~40ms.
            } elseif ($global && !$chatId) {
                return true;
            }
            if ($this->nowMs() >= $deadline) { return false; }
            usleep(self::RETRY_INTERVAL_MS * 1000);
        }
    }

    /**
     * Purge inactive bucket rows. Called from the cron alongside
     * SentMessageStore::purgeExpired().
     */
    public function purgeInactive($olderThanSeconds = 86400) {
        try {
            $this->ensureSchema();
            $cutoff = $this->nowMs() - ((int) $olderThanSeconds * 1000);
            db_query(sprintf(
                "DELETE FROM %s WHERE last_refill_ms < %d",
                $this->table,
                $cutoff
            ));
        } catch (Exception $e) {
            error_log('[TgRateLimiter.purgeInactive] ' . $e->getMessage());
        }
    }

    // ─── Internals ───────────────────────────────────────────────────────

    /**
     * Atomically consume 1 token from a bucket. Refills based on elapsed
     * milliseconds since last_refill_ms. Returns true if we got a token.
     *
     * Uses a compare-and-swap-ish UPDATE with WHERE tokens >= 1 so
     * concurrent workers can race safely without a SELECT-FOR-UPDATE
     * transaction (which would serialize everything).
     */
    private function consume($key, $capacity, $refillPerSec) {
        try {
            $this->ensureSchema();
            $now = $this->nowMs();

            // Try INSERT IGNORE the seed row (capacity=full at now).
            db_query(sprintf(
                "INSERT IGNORE INTO %s (`bucket_key`, `tokens_x1000`, `last_refill_ms`) "
                . "VALUES ('%s', %d, %d)",
                $this->table,
                db_input($key, false),
                (int) round($capacity * 1000),
                $now
            ));

            // Read current state.
            $res = db_query(sprintf(
                "SELECT `tokens_x1000`, `last_refill_ms` FROM %s WHERE `bucket_key`='%s' LIMIT 1",
                $this->table,
                db_input($key, false)
            ));
            $row = $res ? db_fetch_array($res) : null;
            if (!$row) { return false; }

            $tokensX1000  = (int) $row['tokens_x1000'];
            $lastRefillMs = (int) $row['last_refill_ms'];
            $elapsedMs    = max(0, $now - $lastRefillMs);
            $refillTokens = ($elapsedMs / 1000.0) * $refillPerSec;
            $newTokensX1000 = (int) min($capacity * 1000, $tokensX1000 + $refillTokens * 1000);

            if ($newTokensX1000 < 1000) { return false; } // not even 1 token

            // Atomic decrement — WHERE tokens_x1000 = old value ensures
            // only ONE concurrent worker wins if they refilled the same
            // amount. Loser will re-loop via tryAcquire.
            $ok = db_query(sprintf(
                "UPDATE %s SET `tokens_x1000`=%d, `last_refill_ms`=%d "
                . "WHERE `bucket_key`='%s' AND `tokens_x1000`=%d AND `last_refill_ms`=%d",
                $this->table,
                $newTokensX1000 - 1000,
                $now,
                db_input($key, false),
                $tokensX1000,
                $lastRefillMs
            ));
            return $ok && db_affected_rows() === 1;
        } catch (Exception $e) {
            // On failure, fail-open — better to occasionally hit 429 than
            // to block all sends because our bucket table died. The 429
            // itself will be classified and handled by TelegramBotClient.
            error_log('[TgRateLimiter.consume] ' . $e->getMessage());
            return true;
        }
    }

    private function nowMs() {
        return (int) round(microtime(true) * 1000);
    }

    private function ensureSchema() {
        if ($this->schemaReady) { return; }
        $sql = "CREATE TABLE IF NOT EXISTS `" . $this->table . "` ("
            . "`bucket_key` VARCHAR(64) NOT NULL,"
            . "`tokens_x1000` INT UNSIGNED NOT NULL,"
            . "`last_refill_ms` BIGINT UNSIGNED NOT NULL,"
            . "PRIMARY KEY (`bucket_key`),"
            . "KEY `idx_last_refill` (`last_refill_ms`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            db_query($sql);
            $this->schemaReady = true;
        } catch (Exception $e) {
            $code = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;
            if ($code === 1050 || $code === 1061) { $this->schemaReady = true; return; }
            error_log('[TgRateLimiter.ensureSchema] ' . $e->getMessage());
        }
    }
}
