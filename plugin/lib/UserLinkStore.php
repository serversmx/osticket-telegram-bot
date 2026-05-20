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
    /** @var int default seconds — 15 min */
    private $tokenTtl;

    public function __construct($tokenTtl = 900) {
        $this->tableLinks  = TABLE_PREFIX . 'telegram_links';
        $this->tableTokens = TABLE_PREFIX . 'telegram_link_tokens';
        $this->tokenTtl    = (int) $tokenTtl;
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
        if (!$this->tablesReady) { return; }
        db_query('DELETE FROM ' . $this->tableLinks . ' WHERE user_id=' . (int) $userId);
    }

    /** Remove the mapping for a chat (used by /unlink command). */
    public function unlinkByChat($chatId) {
        if (!$this->tablesReady) { return; }
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
