<?php
/**
 * Tests for TgInlineKeyboard.
 * Run: php tests/InlineKeyboardBuilderTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/InlineKeyboardBuilder.php';

class InlineKeyboardBuilderTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_empty_build_returns_null();
        $this->test_single_url_button();
        $this->test_rejects_non_http_url();
        $this->test_rejects_empty_label();
        $this->test_multiple_rows();
        $this->test_callback_button();
        $this->test_callback_button_max_64_bytes();
        $this->test_row_cap_8_buttons();
        $this->test_global_cap_100_buttons();
        $this->test_is_empty();

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

    private function test_empty_build_returns_null() {
        $kb = new TgInlineKeyboard();
        $this->assertSame(null, $kb->build(), 'empty keyboard returns null');
        $this->assertSame(true, $kb->isEmpty(), 'isEmpty true initially');
    }

    private function test_single_url_button() {
        $kb = new TgInlineKeyboard();
        $kb->addRow()->urlButton('Open', 'https://example.com/');
        $out = $kb->build();
        $expected = array('inline_keyboard' => array(
            array(array('text' => 'Open', 'url' => 'https://example.com/')),
        ));
        $this->assertSame($expected, $out, 'single URL button');
    }

    private function test_rejects_non_http_url() {
        $kb = new TgInlineKeyboard();
        $kb->addRow()->urlButton('Bad', 'javascript:alert(1)');
        $this->assertSame(null, $kb->build(), 'javascript: URL rejected');
    }

    private function test_rejects_empty_label() {
        $kb = new TgInlineKeyboard();
        $kb->addRow()->urlButton('', 'https://example.com/');
        $this->assertSame(null, $kb->build(), 'empty label rejected');
    }

    private function test_multiple_rows() {
        $kb = new TgInlineKeyboard();
        $kb->addRow()->urlButton('A', 'https://a.example/');
        $kb->addRow()->urlButton('B', 'https://b.example/');
        $out = $kb->build();
        $this->assertSame(2, count($out['inline_keyboard']), 'two rows');
        $this->assertSame('A', $out['inline_keyboard'][0][0]['text'], 'row 1 text');
        $this->assertSame('B', $out['inline_keyboard'][1][0]['text'], 'row 2 text');
    }

    private function test_callback_button() {
        $kb = new TgInlineKeyboard();
        $kb->addRow()->callbackButton('Click', 'evt:close:1234');
        $out = $kb->build();
        $expected = array('inline_keyboard' => array(
            array(array('text' => 'Click', 'callback_data' => 'evt:close:1234')),
        ));
        $this->assertSame($expected, $out, 'callback button');
    }

    private function test_callback_button_max_64_bytes() {
        $kb = new TgInlineKeyboard();
        // 65 bytes — should be rejected.
        $kb->addRow()->callbackButton('x', str_repeat('a', 65));
        $this->assertSame(null, $kb->build(), 'callback_data > 64 bytes rejected');
    }

    private function test_row_cap_8_buttons() {
        $kb = new TgInlineKeyboard();
        $kb->addRow();
        for ($i = 0; $i < 12; $i++) {
            $kb->urlButton('B' . $i, 'https://example.com/' . $i);
        }
        $out = $kb->build();
        $this->assertSame(8, count($out['inline_keyboard'][0]), 'row capped at 8 buttons');
    }

    private function test_global_cap_100_buttons() {
        $kb = new TgInlineKeyboard();
        // 200 buttons total split into 25 rows of 8.
        for ($r = 0; $r < 25; $r++) {
            $kb->addRow();
            for ($b = 0; $b < 8; $b++) {
                $kb->urlButton('B', 'https://example.com/');
            }
        }
        $out = $kb->build();
        $total = 0;
        foreach ($out['inline_keyboard'] as $row) {
            $total += count($row);
        }
        $this->assertSame(100, $total, 'global cap 100 buttons');
    }

    private function test_is_empty() {
        $kb = new TgInlineKeyboard();
        $this->assertSame(true, $kb->isEmpty(), 'fresh keyboard empty');
        $kb->addRow();
        $this->assertSame(true, $kb->isEmpty(), 'empty row counts as empty');
        $kb->urlButton('X', 'https://e.example/');
        $this->assertSame(false, $kb->isEmpty(), 'after a button, not empty');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new InlineKeyboardBuilderTest();
    $t->run();
    echo "OK\n";
}
