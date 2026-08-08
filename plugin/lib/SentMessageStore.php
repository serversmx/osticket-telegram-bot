<?php
/**
 * SentMessageStore — persists (chat_id, ticket_id, notif_type, message_id)
 * so we can later edit ticket-related Telegram messages when the ticket's
 * state changes (closed / deleted / assigned / etc.).
 *
 * Design invariants (from the 2026-08-08 adversarial review):
 *
 *   1. INSERT-before-send: reserve() writes a row with status='pending'
 *      BEFORE dispatchSend calls sendMessage. commit() then UPDATEs the
 *      pending row with the real message_id + status='sent'. abort()
 *      DELETEs the pending row on send failure. This closes the
 *      send-completes-after-delete race: syncTicketState always sees at
 *      least a placeholder row.
 *
 *   2. failure_count instead of hard-delete: a transient 'gone' from
 *      Telegram (regional outage) increments failure_count but does NOT
 *      delete the row until 3 consecutive lifecycle-event failures. purge
 *      still cleans by sent_at TTL.
 *
 *   3. UNIQUE (chat_id, message_id): Telegram's own natural key. Prevents
 *      any double-tracking bug from turning into two rows pointing at
 *      the same real message.
 *
 *   4. subject_snapshot: the ticket subject captured at record-time so
 *      that a later delete-cascade can render the "was: <subject>" line
 *      even after Ticket::lookup() returns null.
 *
 * @license GPL-2.0-or-later
 */

class TgSentMessageStore {

    // Telegram's edit window is 48h; we filter selects at 47h so latency
    // between SELECT and API call doesn't push us over the wall.
    const EDIT_WINDOW_SECONDS = 169200;   // 47h
    // Rows past 72h are unrecoverable — purge cron cleans them.
    const RETENTION_SECONDS   = 259200;   // 72h
    // How many consecutive lifecycle-event failures before we give up on a row.
    const FAILURE_THRESHOLD   = 3;

    /** @var string Fully qualified table name (with prefix). */
    private $table;

    /** @var bool One-shot flag to avoid running CREATE TABLE per request. */
    private $schemaReady = false;

    public function __construct($tablePrefix = null) {
        $prefix = $tablePrefix !== null ? $tablePrefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_');
        $this->table = $prefix . 'telegram_sent_messages';
    }

    /**
     * Reserve a pending row BEFORE calling sendMessage. Returns the row id
     * so the caller can commit() or abort() based on the API result.
     *
     * @return int|null row id, or null on failure (never blocks the send).
     */
    public function reserve($chatId, $ticketId, $notifType, $subject = '') {
        try {
            $this->ensureSchema();
            $ok = db_query(sprintf(
                "INSERT INTO %s (chat_id, ticket_id, notif_type, message_id, status, sent_at, subject_snapshot, failure_count) "
                . "VALUES (%d, %d, '%s', 0, 'pending', %d, '%s', 0)",
                $this->table,
                (int) $chatId,
                (int) $ticketId,
                db_input((string) $notifType, false),
                time(),
                db_input((string) $subject, false)
            ));
            return $ok ? (int) db_insert_id() : null;
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.reserve] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert a pending row into a real send record. Called after
     * sendMessage returned ok=true with the actual Telegram message_id.
     */
    public function commit($rowId, $messageId) {
        if (!$rowId || !$messageId) { return false; }
        try {
            return (bool) db_query(sprintf(
                "UPDATE %s SET message_id=%d, status='sent' WHERE id=%d",
                $this->table,
                (int) $messageId,
                (int) $rowId
            ));
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.commit] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Discard a pending row on send failure — keeps the table from
     * accumulating dead placeholders.
     */
    public function abort($rowId) {
        if (!$rowId) { return; }
        try {
            db_query(sprintf("DELETE FROM %s WHERE id=%d AND status='pending'", $this->table, (int) $rowId));
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.abort] ' . $e->getMessage());
        }
    }

    /**
     * Find all live (non-purged, within edit window, healthy) message
     * rows for a ticket. Used by syncTicketState to fan out edits.
     */
    public function findByTicket($ticketId) {
        try {
            $this->ensureSchema();
            $cutoff = time() - self::EDIT_WINDOW_SECONDS;
            $res = db_query(sprintf(
                "SELECT id, chat_id, message_id, notif_type, sent_at, subject_snapshot, failure_count "
                . "FROM %s WHERE ticket_id=%d AND status='sent' AND sent_at >= %d AND failure_count < %d",
                $this->table,
                (int) $ticketId,
                $cutoff,
                self::FAILURE_THRESHOLD
            ));
            $out = array();
            if ($res) {
                while (($row = db_fetch_array($res))) { $out[] = $row; }
            }
            return $out;
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.findByTicket] ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Increment failure_count on a specific (chat_id, message_id). Called
     * when Telegram returns 'gone' / 'expired' / 'can't be edited'.
     *
     * Returns the new failure_count so caller can decide whether to log
     * a warning (threshold reached) or a breadcrumb (transient).
     */
    public function recordFailure($chatId, $messageId) {
        try {
            db_query(sprintf(
                "UPDATE %s SET failure_count = failure_count + 1 "
                . "WHERE chat_id=%d AND message_id=%d",
                $this->table,
                (int) $chatId,
                (int) $messageId
            ));
            $res = db_query(sprintf(
                "SELECT failure_count FROM %s WHERE chat_id=%d AND message_id=%d LIMIT 1",
                $this->table,
                (int) $chatId,
                (int) $messageId
            ));
            $row = $res ? db_fetch_array($res) : null;
            return $row ? (int) $row['failure_count'] : 0;
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.recordFailure] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete all rows for a ticket — called when the callback handler
     * discovers the ticket no longer exists (out-of-band delete).
     * Prevents future lifecycle events from trying to edit dead cards.
     */
    public function deleteForTicket($ticketId) {
        try {
            db_query(sprintf("DELETE FROM %s WHERE ticket_id=%d", $this->table, (int) $ticketId));
        } catch (Exception $e) {
            error_log('[TgSentMessageStore.deleteForTicket] ' . $e->getMessage());
        }
    }

    /**
     * Delete rows past retention. Chunked to avoid long transactions.
     * Returns number of rows deleted this pass. Caller (cron) loops with
     * a bounded max iterations to avoid starvation under write churn.
     */
    public function purgeExpired($olderThanSeconds = null, $chunkSize = 1000, $maxIterations = 20) {
        $olderThan = (int) ($olderThanSeconds !== null ? $olderThanSeconds : self::RETENTION_SECONDS);
        $chunkSize = max(1, min(10000, (int) $chunkSize));
        $maxIter   = max(1, (int) $maxIterations);
        try {
            $this->ensureSchema();
        } catch (Exception $e) {
            return 0;
        }
        $cutoff = time() - $olderThan;
        $totalDeleted = 0;
        for ($i = 0; $i < $maxIter; $i++) {
            try {
                $ok = db_query(sprintf(
                    "DELETE FROM %s WHERE sent_at < %d LIMIT %d",
                    $this->table,
                    $cutoff,
                    $chunkSize
                ));
                if (!$ok) { break; }
                $n = (int) db_affected_rows();
                $totalDeleted += $n;
                if ($n === 0) { break; }
            } catch (Exception $e) {
                error_log('[TgSentMessageStore.purgeExpired] ' . $e->getMessage());
                break;
            }
        }
        return $totalDeleted;
    }

    /**
     * CREATE TABLE IF NOT EXISTS with the exact schema from design v2:
     *  - chat_id BIGINT SIGNED (Telegram supergroup ids are negative)
     *  - message_id BIGINT UNSIGNED
     *  - status ENUM('pending','sent') for the INSERT-before-send pattern
     *  - subject_snapshot for post-delete decoration
     *  - failure_count for the 3-strike hard-delete guard
     *  - UNIQUE (chat_id, message_id) — Telegram's natural key
     *  - Index on (ticket_id) for the sync fan-out lookup
     *  - Index on (sent_at) for the purge cron
     */
    private function ensureSchema() {
        if ($this->schemaReady) { return; }
        $sql = "CREATE TABLE IF NOT EXISTS `" . $this->table . "` ("
            . "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "`chat_id` BIGINT NOT NULL,"
            . "`ticket_id` INT UNSIGNED NOT NULL,"
            . "`notif_type` VARCHAR(32) NOT NULL DEFAULT '',"
            . "`message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "`status` ENUM('pending','sent') NOT NULL DEFAULT 'pending',"
            . "`sent_at` INT UNSIGNED NOT NULL,"
            . "`subject_snapshot` VARCHAR(255) NOT NULL DEFAULT '',"
            . "`failure_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (`id`),"
            . "UNIQUE KEY `uniq_chat_msg` (`chat_id`, `message_id`),"
            . "KEY `idx_ticket` (`ticket_id`),"
            . "KEY `idx_sent_at` (`sent_at`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            db_query($sql);
            $this->schemaReady = true;
        } catch (Exception $e) {
            // 1050 (table exists) / 1061 (dup key) are benign on re-runs.
            $code = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;
            if ($code === 1050 || $code === 1061) {
                $this->schemaReady = true;
                return;
            }
            error_log('[TgSentMessageStore.ensureSchema] ' . $e->getMessage());
        }
    }
}
