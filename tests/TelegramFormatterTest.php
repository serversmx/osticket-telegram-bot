<?php
/**
 * Tests for TgFormatter.
 * Run: php tests/TelegramFormatterTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/TelegramFormatter.php';

class TelegramFormatterTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_render_basic();
        $this->test_render_missing_key_empty();
        $this->test_render_fallback();
        $this->test_render_array_value_empty();

        $this->test_escape_markdown_v2_reserved();
        $this->test_escape_markdown_v2_plain_text();
        $this->test_escape_markdown_v2_dot_and_dash();

        $this->test_escape_html();

        $this->test_html_to_telegram_strips_unknown_tags();
        $this->test_html_to_telegram_preserves_bold();
        $this->test_html_to_telegram_br_to_newline();
        $this->test_html_to_telegram_strips_span_class();
        $this->test_html_to_telegram_strips_matching_close_span();
        $this->test_html_to_telegram_strips_styled_span_pair();
        $this->test_html_to_telegram_preserves_tg_spoiler();

        $this->test_html_to_md2_bold_escapes_inner();
        $this->test_html_to_md2_link();
        $this->test_html_to_md2_plain_text_escapes();

        $this->test_truncate_short_unchanged();
        $this->test_truncate_long();

        echo "\n-- Summary --\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        if ($this->failed) {
            foreach ($this->failures as $f) {
                echo "  - $f\n";
            }
            exit(1);
        }
    }

    private function assertSame($expected, $actual, $msg) {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->failures[] = sprintf('%s — expected %s, got %s',
            $msg, var_export($expected, true), var_export($actual, true));
    }

    private function assertContains($needle, $haystack, $msg) {
        if (strpos($haystack, $needle) !== false) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->failures[] = sprintf('%s — expected substring %s in %s',
            $msg, var_export($needle, true), var_export($haystack, true));
    }

    // ─── render ───────────────────────────────────────────────────────────

    private function test_render_basic() {
        $this->assertSame('Hi Renato, ticket #1',
            TgFormatter::render('Hi {{name}}, ticket #{{num}}', array('name' => 'Renato', 'num' => 1)),
            'basic substitution');
    }
    private function test_render_missing_key_empty() {
        $this->assertSame('A=, B=42',
            TgFormatter::render('A={{a}}, B={{b}}', array('b' => 42)),
            'missing key → empty');
    }
    private function test_render_fallback() {
        $this->assertSame('A=fb, B=42',
            TgFormatter::render('A={{a|fb}}, B={{b|nope}}', array('b' => 42)),
            'fallback honored when key missing');
    }
    private function test_render_array_value_empty() {
        $this->assertSame('X=, Y=1',
            TgFormatter::render('X={{x}}, Y={{y}}', array('x' => array(), 'y' => 1)),
            'array value → empty');
    }

    // ─── escapeMarkdownV2 ─────────────────────────────────────────────────

    private function test_escape_markdown_v2_reserved() {
        $in  = '_*[]()~`>#+-=|{}.!';
        $out = TgFormatter::escapeMarkdownV2($in);
        $expected = '\\_\\*\\[\\]\\(\\)\\~\\`\\>\\#\\+\\-\\=\\|\\{\\}\\.\\!';
        $this->assertSame($expected, $out, 'every MD2 reserved char gets escaped');
    }
    private function test_escape_markdown_v2_plain_text() {
        $this->assertSame('hello world',
            TgFormatter::escapeMarkdownV2('hello world'),
            'plain text untouched');
    }
    private function test_escape_markdown_v2_dot_and_dash() {
        $this->assertSame('Version 1\\.0\\-beta',
            TgFormatter::escapeMarkdownV2('Version 1.0-beta'),
            '. and - both escaped');
    }

    // ─── escapeHtml ───────────────────────────────────────────────────────

    private function test_escape_html() {
        $this->assertSame('Tom &amp; &lt;Jerry&gt;',
            TgFormatter::escapeHtml('Tom & <Jerry>'),
            'HTML special chars escaped');
    }

    // ─── htmlToTelegram ───────────────────────────────────────────────────

    private function test_html_to_telegram_strips_unknown_tags() {
        $this->assertSame('hello world',
            TgFormatter::htmlToTelegram('<div class="x">hello</div> <article>world</article>'),
            'unknown tags stripped');
    }
    private function test_html_to_telegram_preserves_bold() {
        $out = TgFormatter::htmlToTelegram('<b>important</b> note');
        $this->assertContains('<b>important</b>', $out, 'bold preserved');
    }
    private function test_html_to_telegram_br_to_newline() {
        $this->assertSame("a\nb",
            TgFormatter::htmlToTelegram('a<br>b'),
            'br → newline');
    }
    private function test_html_to_telegram_strips_span_class() {
        $out = TgFormatter::htmlToTelegram('<span class="evil">secret</span>');
        // tg-spoiler is the only allowed span class — others stripped.
        if (strpos($out, '<span class="evil"') !== false) {
            $this->failed++;
            $this->failures[] = 'span with evil class leaked';
        } else {
            $this->passed++;
        }
    }
    // Regression: an unmatched closing </span> crashed Telegram with
    // "Bad Request: can't parse entities: Unmatched end tag" because the
    // open tag was stripped but the close tag was left behind.
    private function test_html_to_telegram_strips_matching_close_span() {
        $out = TgFormatter::htmlToTelegram('<span class="evil">secret</span>');
        if (strpos($out, '</span>') !== false) {
            $this->failed++;
            $this->failures[] = 'closing </span> leaked when open was stripped: ' . var_export($out, true);
        } else {
            $this->passed++;
        }
        // Content must still be preserved.
        $this->assertContains('secret', $out, 'span content preserved');
    }
    private function test_html_to_telegram_strips_styled_span_pair() {
        // Real-world case from osTicket Redactor output that triggered
        // "Bad Request: can't parse entities: Unmatched end tag at byte
        // offset 1643, expected </a>, found </span>".
        $in = '<a href="https://example.com">link</a> with <span style="color:#e63946;font-weight:bold">red</span> word';
        $out = TgFormatter::htmlToTelegram($in);
        if (substr_count($out, '<span') !== substr_count($out, '</span>')) {
            $this->failed++;
            $this->failures[] = 'unbalanced span tags in output: ' . var_export($out, true);
        } else {
            $this->passed++;
        }
        $this->assertContains('red', $out, 'styled span content kept');
        $this->assertContains('<a href="https://example.com">link</a>', $out, 'sibling anchor untouched');
    }
    private function test_html_to_telegram_preserves_tg_spoiler() {
        $out = TgFormatter::htmlToTelegram('<span class="tg-spoiler">hidden</span>');
        $this->assertSame('<span class="tg-spoiler">hidden</span>', $out,
            'tg-spoiler span kept verbatim');
    }

    // ─── htmlToMarkdownV2 ────────────────────────────────────────────────

    private function test_html_to_md2_bold_escapes_inner() {
        $out = TgFormatter::htmlToMarkdownV2('<b>1.0</b>');
        // Inner "1.0" should be MD2-escaped because dot is reserved.
        $this->assertSame('*1\\.0*', $out, 'inner of bold gets MD2 escaped');
    }
    private function test_html_to_md2_link() {
        $out = TgFormatter::htmlToMarkdownV2('<a href="https://example.com/x?a=1">click</a>');
        $this->assertContains('[click]', $out, 'link label kept');
        $this->assertContains('https://example.com/x?a=1', $out, 'link URL kept');
    }
    private function test_html_to_md2_plain_text_escapes() {
        $out = TgFormatter::htmlToMarkdownV2('Version 1.0 (final)');
        // Plain text containing dot and parens should be escaped.
        $this->assertContains('1\\.0', $out, 'plain text dot escaped');
        $this->assertContains('\\(final\\)', $out, 'plain text parens escaped');
    }

    // ─── truncate ─────────────────────────────────────────────────────────

    private function test_truncate_short_unchanged() {
        $this->assertSame('short', TgFormatter::truncate('short', 10), 'short text unchanged');
    }
    private function test_truncate_long() {
        $out = TgFormatter::truncate(str_repeat('a', 100), 10);
        $len = function_exists('mb_strlen') ? mb_strlen($out, 'UTF-8') : strlen($out);
        $this->assertSame(10, $len, 'truncated to max length');
        $this->assertSame('…', substr($out, -strlen('…')), 'ellipsis appended');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new TelegramFormatterTest();
    $t->run();
    echo "OK\n";
}
