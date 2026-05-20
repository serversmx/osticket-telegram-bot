<?php
/**
 * Builder for Telegram inline keyboards.
 *
 * Usage:
 *   $kb = new TgInlineKeyboard();
 *   $kb->addRow()
 *      ->urlButton('View ticket', 'https://example.com/scp/tickets.php?id=1')
 *      ->urlButton('Reply', 'https://example.com/scp/tickets.php?id=1#reply');
 *   $markup = $kb->build();   // array suitable as `reply_markup` for sendMessage
 *
 * Telegram constraints (validated here):
 *   - Each row: up to 8 buttons
 *   - Whole keyboard: up to 100 buttons total
 *   - URL buttons must have a valid http(s):// or tg:// scheme
 *   - Callback data must be ≤ 64 bytes
 *
 * @license GPL-2.0-or-later
 * @link    https://core.telegram.org/bots/api#inlinekeyboardmarkup
 */
class TgInlineKeyboard {

    /** @var array Stack of row arrays, each row is an array of button arrays. */
    private $rows = array();
    /** @var int|null Pointer to the current row index for chained calls. */
    private $current = null;

    /** Start a new row. Chainable. */
    public function addRow() {
        $this->rows[] = array();
        $this->current = count($this->rows) - 1;
        return $this;
    }

    /**
     * Add a URL button to the current row.
     *
     * @param string $text  Button label.
     * @param string $url   Target URL; must be http(s):// or tg://.
     * @return $this        Chainable.
     */
    public function urlButton($text, $url) {
        $text = trim((string) $text);
        $url  = trim((string) $url);
        if ($text === '' || $url === '') {
            return $this;
        }
        if (!preg_match('#^(https?|tg)://#i', $url)) {
            return $this;
        }
        $this->ensureRow();
        $this->rows[$this->current][] = array(
            'text' => $text,
            'url'  => $url,
        );
        return $this;
    }

    /**
     * Add a callback-data button to the current row. The plugin's webhook
     * handler can answer to these via answerCallbackQuery.
     *
     * @param string $text  Button label.
     * @param string $data  Callback data, max 64 bytes after UTF-8 encoding.
     */
    public function callbackButton($text, $data) {
        $text = trim((string) $text);
        $data = (string) $data;
        if ($text === '' || $data === '' || strlen($data) > 64) {
            return $this;
        }
        $this->ensureRow();
        $this->rows[$this->current][] = array(
            'text'          => $text,
            'callback_data' => $data,
        );
        return $this;
    }

    /**
     * @return bool True when no buttons are present yet (empty keyboard).
     */
    public function isEmpty() {
        foreach ($this->rows as $r) {
            if (!empty($r)) { return false; }
        }
        return true;
    }

    /**
     * Build the `reply_markup` payload. Returns null when empty (so callers
     * can pass it straight through to sendMessage without an empty keyboard
     * showing up).
     */
    public function build() {
        // Filter out empty rows and trim row sizes (max 8 buttons each).
        $clean = array();
        $total = 0;
        foreach ($this->rows as $row) {
            if (empty($row)) {
                continue;
            }
            $row = array_slice($row, 0, 8);
            // Cap global button count at 100.
            $remaining = 100 - $total;
            if ($remaining <= 0) { break; }
            if (count($row) > $remaining) {
                $row = array_slice($row, 0, $remaining);
            }
            $clean[] = array_values($row);
            $total += count($row);
        }
        if (empty($clean)) {
            return null;
        }
        return array('inline_keyboard' => $clean);
    }

    private function ensureRow() {
        if ($this->current === null) {
            $this->addRow();
        }
    }
}
