<?php
/**
 * Tiny template + Telegram formatting helpers.
 *
 *   - render():         substitute {{var}} and {{var|fallback}} tokens.
 *   - escapeMarkdownV2: escape every reserved MarkdownV2 character.
 *   - escapeHtml:       escape <, >, & for Telegram HTML parse mode.
 *   - htmlToTelegram:   convert source HTML (from osTicket WYSIWYG) to the
 *                       restricted HTML subset Telegram supports.
 *   - truncate:         UTF-8 safe truncation with ellipsis.
 *
 * @license GPL-2.0-or-later
 */
class TgFormatter {

    /** MarkdownV2 reserved characters — every one MUST be backslash-escaped. */
    private static $MD2_RESERVED = array(
        '_', '*', '[', ']', '(', ')', '~', '`', '>', '#',
        '+', '-', '=', '|', '{', '}', '.', '!',
    );

    /**
     * Substitute placeholders. Same semantics as the Evolution plugin's
     * renderer: missing keys render empty, `{{var|fallback}}` supports
     * defaults, non-scalar values render empty.
     */
    public static function render($template, array $vars) {
        if ($template === null) {
            return '';
        }
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*(?:\|\s*([^}]*))?\}\}/',
            function ($m) use ($vars) {
                $key = $m[1];
                $fallback = isset($m[2]) ? $m[2] : '';
                if (array_key_exists($key, $vars)) {
                    $v = $vars[$key];
                    if (is_scalar($v)) { return (string) $v; }
                    if ($v === null)   { return $fallback; }
                    return '';
                }
                return $fallback;
            },
            (string) $template
        );
    }

    /**
     * Escape a literal text fragment for safe inclusion in a MarkdownV2
     * message. Use this for VARIABLE values that the admin doesn't control
     * (e.g. customer name) — never wrap the whole template, only the parts
     * that come from user input.
     */
    public static function escapeMarkdownV2($text) {
        $s = (string) $text;
        $escaped = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (in_array($c, self::$MD2_RESERVED, true)) {
                $escaped .= '\\';
            }
            $escaped .= $c;
        }
        return $escaped;
    }

    /**
     * Escape variable text for Telegram HTML parse mode. Only `<`, `>`, `&`
     * matter — Telegram allows the rest unescaped.
     */
    public static function escapeHtml($text) {
        $s = (string) $text;
        $s = str_replace('&', '&amp;', $s);
        $s = str_replace('<', '&lt;', $s);
        $s = str_replace('>', '&gt;', $s);
        return $s;
    }

    /**
     * Best-effort conversion from osTicket's WYSIWYG HTML to Telegram's
     * supported subset:
     *   <b>, <strong>, <i>, <em>, <u>, <ins>, <s>, <strike>, <del>,
     *   <a href="…">, <code>, <pre>, <br>, <span class="tg-spoiler">,
     *   <blockquote>
     *
     * Everything else is stripped. Suitable when the admin has chosen
     * HTML parse mode.
     */
    public static function htmlToTelegram($html) {
        $html = (string) $html;
        // <br>, <p> → newlines.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);
        $html = preg_replace('#<p[^>]*>#i', '', $html);
        $html = preg_replace('#</p>#i', '', $html);
        $html = preg_replace('#<li[^>]*>#i', '• ', $html);
        $html = preg_replace('#</li>#i', "\n", $html);

        $allowed = '<b><strong><i><em><u><ins><s><strike><del><a><code><pre><br><span><blockquote>';
        $stripped = strip_tags($html, $allowed);

        // Telegram doesn't actually allow <span class="…"> — strip class
        // attributes off everything except tg-spoiler.
        $stripped = preg_replace_callback('#<span\b([^>]*)>#i', function ($m) {
            if (strpos($m[1], 'tg-spoiler') !== false) {
                return '<span class="tg-spoiler">';
            }
            return '';
        }, $stripped);

        // Decode HTML entities so users see them rendered, not as `&amp;`.
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of blank lines.
        $stripped = preg_replace("/\n{3,}/", "\n\n", $stripped);
        return trim($stripped);
    }

    /**
     * Convert osTicket WYSIWYG HTML to MarkdownV2-safe plain text. Bold,
     * italic, code, and links are preserved; everything else stripped.
     *
     * Strategy: extract each inline-formatted element into a placeholder
     * keyed by a NUL-byte sentinel, then escape the surrounding plain text
     * once, then restore the placeholders. This avoids double-escaping the
     * MD2 syntax we ourselves emit.
     */
    public static function htmlToMarkdownV2($html) {
        $html = (string) $html;
        // <br>, <p>, <li> → newlines / list markers.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);
        $html = preg_replace('#<p[^>]*>#i', '', $html);
        $html = preg_replace('#</p>#i', '', $html);
        $html = preg_replace('#<li[^>]*>#i', '• ', $html);
        $html = preg_replace('#</li>#i', "\n", $html);

        // Use a unique hex sentinel per call. Hex chars (0-9a-f) are not
        // MarkdownV2-reserved, so the placeholder survives escapeMarkdownV2,
        // strip_tags, and html_entity_decode intact.
        $sentinel = 'TGMK' . self::randomHex8() . 'TGMK';
        $store    = array();
        $stash = function ($value) use (&$store, $sentinel) {
            $store[] = $value;
            return $sentinel . (count($store) - 1) . $sentinel;
        };

        // Stash inline elements as opaque placeholders.
        // Bold (<b>, <strong>)
        $html = preg_replace_callback('#<(strong|b)\b[^>]*>(.*?)</\1>#is', function ($m) use ($stash) {
            return $stash('*' . self::escapeMarkdownV2(strip_tags($m[2])) . '*');
        }, $html);
        // Italic (<i>, <em>)
        $html = preg_replace_callback('#<(em|i)\b[^>]*>(.*?)</\1>#is', function ($m) use ($stash) {
            return $stash('_' . self::escapeMarkdownV2(strip_tags($m[2])) . '_');
        }, $html);
        // Code spans
        $html = preg_replace_callback('#<code\b[^>]*>(.*?)</code>#is', function ($m) use ($stash) {
            return $stash('`' . str_replace('`', '\\`', strip_tags($m[1])) . '`');
        }, $html);
        // Anchors
        $html = preg_replace_callback('#<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) use ($stash) {
            $label = self::escapeMarkdownV2(strip_tags($m[2]));
            // Inside an MD2 link target, only `\` and `)` need escaping.
            $url   = str_replace(array('\\', ')'), array('\\\\', '\\)'), $m[1]);
            return $stash('[' . $label . '](' . $url . ')');
        }, $html);

        // Strip remaining tags + decode entities.
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Escape the surrounding plain text. Placeholders survive because
        // NUL bytes are not in the reserved set.
        $text = self::escapeMarkdownV2($text);

        // Restore placeholders.
        $text = preg_replace_callback(
            '/' . preg_quote($sentinel, '/') . '(\d+)' . preg_quote($sentinel, '/') . '/',
            function ($m) use (&$store) {
                $i = (int) $m[1];
                return isset($store[$i]) ? $store[$i] : '';
            },
            $text
        );

        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function randomHex8() {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(4));
        }
        return sprintf('%08x', mt_rand());
    }

    /**
     * UTF-8 safe truncation with ellipsis.
     */
    public static function truncate($text, $max = 3500) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') <= $max) { return $text; }
            return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
        }
        if (strlen($text) <= $max) { return $text; }
        return substr($text, 0, $max - 1) . '…';
    }
}
