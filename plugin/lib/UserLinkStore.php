<?php
/**
 * Persistence for the customer ↔ Telegram chat linking flow.
 *
 * Two tables, auto-created on first use:
 *
 *   <prefix>telegram_links — confirmed mappings between an osTicket user
 *   and a Telegram chat:
 *
 *     user_id   INT UNSIGNED PRIMARY KEY
 *     chat_id   BIGINT NOT NULL UNIQUE
 *     linked_at INT UNSIGNED NOT NULL
 *
 *   <prefix>telegram_link_tokens — short-lived one-shot tokens generated
 *   when a user clicks "Link Telegram" on their profile:
 *
 *     token     CHAR(32) PRIMARY KEY    (hex)
 *     user_id   INT UNSIGNED NOT NULL
 *     created   INT UNSIGNED NOT NULL
 *     ttl       INT UNSIGNED NOT NULL   (seconds)
 *
 * The webhook handler consumes tokens (deletes on use) and writes to
 * telegram_links on success.
 *
 * @license GPL-2.0-or-later
 */
class TgUserLinkStore {

    private $tableLinks;
    private $tableTokens;
    private $tablesReady = false;
    // Parallel set of tables for staff (admin) linking — same schema, different
    // primary-key meaning. Auto-created lazily on first use, like the user tables.
    private $tableStaffLinks;
    private $tableStaffTokens;
    private $staffTablesReady = false;
    /** @var int default seconds — 15 min */
    private $tokenTtl;

    public function __construct($tokenTtl = 900) {
        $this->tableLinks       = TABLE_PREFIX . 'telegram_links';
        $this->tableTokens      = TABLE_PREFIX . 'telegram_link_tokens';
        $this->tableStaffLinks  = TABLE_PREFIX . 'telegram_staff_links';
        $this->tableStaffTokens = TABLE_PREFIX . 'telegram_staff_link_tokens';
        $this->tokenTtl         = (int) $tokenTtl;
    }

    // ─── Linking flow ────────────────────────────────────────────────────

    /**
     * Generate and persist a one-shot linking token for $userId.
     * Returns the token (raw hex). The bot deep-link will look like
     * `https://t.me/<bot_username>?start=<token>`.
     */
    public function issueToken($userId) {
        $this->ensureTables();
        $token = $this->randomToken();
        $sql = 'REPLACE INTO ' . $this->tableTokens
             . ' (token, user_id, created, ttl) VALUES ('
             . '"' . $this->escape($token) . '", '
             . (int) $userId . ', '
             . time() . ', '
             . $this->tokenTtl . ')';
        db_query($sql);
        return $token;
    }

    /**
     * Consume a token: returns the associated user_id and deletes the row.
     * Returns null when the token is unknown or expired.
     */
    public function consumeToken($token) {
        $this->ensureTables();
        $t = $this->escape((string) $token);
        if ($t === '') { return null; }
        $res = db_query('SELECT user_id, created, ttl FROM ' . $this->tableTokens
            . ' WHERE token="' . $t . '" LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        if (!$row) { return null; }
        // Always delete (one-shot semantics), even if expired.
        db_query('DELETE FROM ' . $this->tableTokens . ' WHERE token="' . $t . '"');
        $age = time() - (int) $row['created'];
        if ($age > (int) $row['ttl']) {
            return null;
        }
        return (int) $row['user_id'];
    }

    /**
     * Confirm a chat_id for a user. Idempotent (REPLACE INTO).
     */
    public function link($userId, $chatId) {
        $this->ensureTables();
        db_query('REPLACE INTO ' . $this->tableLinks
            . ' (user_id, chat_id, linked_at) VALUES ('
            . (int) $userId . ', '
            . (int) $chatId . ', '
            . time() . ')');
    }

    /** Remove the mapping for a user. */
    public function unlinkByUser($userId) {
        // Must ensureTables() — the in-memory $tablesReady flag is per-request
        // and starts false even when the table absolutely exists on disk.
        // A defensive early-return here meant unlink silently no-op'd on
        // fresh requests, leaving the mapping in place.
        $this->ensureTables();
        db_query('DELETE FROM ' . $this->tableLinks . ' WHERE user_id=' . (int) $userId);
    }

    /** Remove the mapping for a chat (used by /unlink command). */
    public function unlinkByChat($chatId) {
        $this->ensureTables();
        db_query('DELETE FROM ' . $this->tableLinks . ' WHERE chat_id=' . (int) $chatId);
    }

    /** Returns the chat_id mapped to $userId, or null. */
    public function chatIdForUser($userId) {
        $this->ensureTables();
        $res = db_query('SELECT chat_id FROM ' . $this->tableLinks
            . ' WHERE user_id=' . (int) $userId . ' LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        if (!$row) { return null; }
        return (int) $row['chat_id'];
    }

    /** Returns the user_id mapped to $chatId, or null. */
    public function userIdForChat($chatId) {
        $this->ensureTables();
        $res = db_query('SELECT user_id FROM ' . $this->tableLinks
            . ' WHERE chat_id=' . (int) $chatId . ' LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        if (!$row) { return null; }
        return (int) $row['user_id'];
    }

    /** Returns true when the user already has a link. */
    public function isUserLinked($userId) {
        return $this->chatIdForUser($userId) !== null;
    }

    /** Drop expired tokens. Safe to call periodically. */
    public function pruneExpiredTokens() {
        if (!$this->tablesReady) { return; }
        db_query('DELETE FROM ' . $this->tableTokens
            . ' WHERE (created + ttl) < ' . time());
        if ($this->staffTablesReady) {
            db_query('DELETE FROM ' . $this->tableStaffTokens
                . ' WHERE (created + ttl) < ' . time());
        }
    }

    // ─── Staff (admin) linking flow ──────────────────────────────────────
    //
    // Parallel set of methods that target the staff tables. A staff member
    // who completes /start <token> gets their chat_id wired into the admin
    // notification recipient list (in addition to whatever's in the manual
    // `admin_chat_ids` config field).

    public function issueStaffToken($staffId) {
        $this->ensureStaffTables();
        $token = $this->randomToken();
        $sql = 'REPLACE INTO ' . $this->tableStaffTokens
             . ' (token, staff_id, created, ttl) VALUES ('
             . '"' . $this->escape($token) . '", '
             . (int) $staffId . ', '
             . time() . ', '
             . $this->tokenTtl . ')';
        db_query($sql);
        return $token;
    }

    /**
     * Consume a staff token. Returns the staff_id or null when missing /
     * expired. Always deletes the row (one-shot).
     */
    public function consumeStaffToken($token) {
        $this->ensureStaffTables();
        $t = $this->escape((string) $token);
        if ($t === '') { return null; }
        $res = db_query('SELECT staff_id, created, ttl FROM ' . $this->tableStaffTokens
            . ' WHERE token="' . $t . '" LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        if (!$row) { return null; }
        db_query('DELETE FROM ' . $this->tableStaffTokens . ' WHERE token="' . $t . '"');
        $age = time() - (int) $row['created'];
        if ($age > (int) $row['ttl']) {
            return null;
        }
        return (int) $row['staff_id'];
    }

    public function linkStaff($staffId, $chatId) {
        $this->ensureStaffTables();
        db_query('REPLACE INTO ' . $this->tableStaffLinks
            . ' (staff_id, chat_id, linked_at) VALUES ('
            . (int) $staffId . ', '
            . (int) $chatId . ', '
            . time() . ')');
    }

    public function unlinkStaffByChat($chatId) {
        // See unlinkByUser() — must ensure tables exist; the in-memory flag
        // starts false per-request and would silently skip the DELETE.
        $this->ensureStaffTables();
        db_query('DELETE FROM ' . $this->tableStaffLinks
            . ' WHERE chat_id=' . (int) $chatId);
    }

    public function unlinkStaffById($staffId) {
        $this->ensureStaffTables();
        db_query('DELETE FROM ' . $this->tableStaffLinks
            . ' WHERE staff_id=' . (int) $staffId);
    }

    public function chatIdForStaff($staffId) {
        $this->ensureStaffTables();
        $res = db_query('SELECT chat_id FROM ' . $this->tableStaffLinks
            . ' WHERE staff_id=' . (int) $staffId . ' LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        return $row ? (int) $row['chat_id'] : null;
    }

    public function staffIdForChat($chatId) {
        $this->ensureStaffTables();
        $res = db_query('SELECT staff_id FROM ' . $this->tableStaffLinks
            . ' WHERE chat_id=' . (int) $chatId . ' LIMIT 1');
        if (!$res) { return null; }
        $row = db_fetch_array($res);
        return $row ? (int) $row['staff_id'] : null;
    }

    /**
     * Returns all chat IDs linked to staff. Used by sendToAdmins() to fan
     * out beyond the manually-configured admin_chat_ids list.
     *
     * @return array<int>
     */
    public function allStaffChatIds() {
        $this->ensureStaffTables();
        $out = array();
        $res = db_query('SELECT chat_id FROM ' . $this->tableStaffLinks);
        if (!$res) { return $out; }
        while (($row = db_fetch_array($res))) {
            $out[] = (int) $row['chat_id'];
        }
        return $out;
    }

    // ─── Internals ───────────────────────────────────────────────────────

    private function ensureTables() {
        if ($this->tablesReady) { return; }
        db_query('CREATE TABLE IF NOT EXISTS ' . $this->tableLinks . ' ('
            . ' user_id INT UNSIGNED NOT NULL PRIMARY KEY,'
            . ' chat_id BIGINT NOT NULL,'
            . ' linked_at INT UNSIGNED NOT NULL,'
            . ' UNIQUE KEY uniq_chat (chat_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db_query('CREATE TABLE IF NOT EXISTS ' . $this->tableTokens . ' ('
            . ' token CHAR(32) NOT NULL PRIMARY KEY,'
            . ' user_id INT UNSIGNED NOT NULL,'
            . ' created INT UNSIGNED NOT NULL,'
            . ' ttl INT UNSIGNED NOT NULL,'
            . ' KEY idx_user (user_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->tablesReady = true;
    }

    private function ensureStaffTables() {
        if ($this->staffTablesReady) { return; }
        db_query('CREATE TABLE IF NOT EXISTS ' . $this->tableStaffLinks . ' ('
            . ' staff_id INT UNSIGNED NOT NULL PRIMARY KEY,'
            . ' chat_id BIGINT NOT NULL,'
            . ' linked_at INT UNSIGNED NOT NULL,'
            . ' UNIQUE KEY uniq_chat (chat_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db_query('CREATE TABLE IF NOT EXISTS ' . $this->tableStaffTokens . ' ('
            . ' token CHAR(32) NOT NULL PRIMARY KEY,'
            . ' staff_id INT UNSIGNED NOT NULL,'
            . ' created INT UNSIGNED NOT NULL,'
            . ' ttl INT UNSIGNED NOT NULL,'
            . ' KEY idx_staff (staff_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->staffTablesReady = true;
    }

    private function randomToken() {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }
        $hex = '';
        for ($i = 0; $i < 16; $i++) {
            $hex .= sprintf('%02x', mt_rand(0, 255));
        }
        return $hex;
    }

    private function escape($s) {
        if (function_exists('db_real_escape')) {
            return db_real_escape((string) $s, false);
        }
        return addslashes((string) $s);
    }
}
