<?php
/**
 * Tests for EvoLogRedactor — PII redaction for log context arrays.
 * Run: php tests/LogRedactorTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/LogRedactor.php';

class LogRedactorTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_passthrough_non_array();
        $this->test_passthrough_unknown_keys();
        $this->test_mask_phone_short();
        $this->test_mask_phone_normal();
        $this->test_mask_phone_array();
        $this->test_preview_text_short();
        $this->test_preview_text_long();
        $this->test_preview_text_collapses_whitespace();
        $this->test_preview_text_non_scalar();
        $this->test_redact_secrets();
        $this->test_recursive_redaction();
        $this->test_case_insensitive_keys();
        $this->test_realistic_log_context();

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

    private function test_passthrough_non_array() {
        $this->assertSame('hello', EvoLogRedactor::context('hello'), 'string passes through');
        $this->assertSame(42, EvoLogRedactor::context(42), 'int passes through');
        $this->assertSame(null, EvoLogRedactor::context(null), 'null passes through');
    }

    private function test_passthrough_unknown_keys() {
        $in  = array('status' => 200, 'url' => 'https://example.com');
        $out = EvoLogRedactor::context($in);
        $this->assertSame($in, $out, 'unknown keys unchanged');
    }

    private function test_mask_phone_short() {
        $this->assertSame('****', EvoLogRedactor::maskPhone('1234'), '4-digit phone fully masked');
        $this->assertSame('***',  EvoLogRedactor::maskPhone('123'),  '3-digit phone fully masked');
    }

    private function test_mask_phone_normal() {
        $this->assertSame('********5678', EvoLogRedactor::maskPhone('525512345678'), 'normal phone shows last 4');
    }

    private function test_mask_phone_array() {
        $in  = array('525511112222', '525533334444');
        $out = EvoLogRedactor::maskPhone($in);
        $this->assertSame(array('********2222', '********4444'), $out, 'array of phones each masked');
    }

    private function test_preview_text_short() {
        $this->assertSame('[5 chars] hello', EvoLogRedactor::previewText('hello'), 'short text shows length + full text');
    }

    private function test_preview_text_long() {
        $long = str_repeat('a', 100);
        $out  = EvoLogRedactor::previewText($long);
        $this->assertSame('[100 chars] ' . str_repeat('a', 40) . '…', $out, 'long text truncated with ellipsis');
    }

    private function test_preview_text_collapses_whitespace() {
        $out = EvoLogRedactor::previewText("line1\nline2\t\ttabs");
        $this->assertSame('[17 chars] line1 line2 tabs', $out, 'whitespace collapsed to single spaces');
    }

    private function test_preview_text_non_scalar() {
        $this->assertSame('[non-scalar]', EvoLogRedactor::previewText(array('x')), 'array → [non-scalar]');
        $this->assertSame('[non-scalar]', EvoLogRedactor::previewText(new stdClass()), 'object → [non-scalar]');
    }

    private function test_redact_secrets() {
        $in  = array('apikey' => 'secret-key-123', 'authorization' => 'Bearer xxx', 'token' => 'abc');
        $out = EvoLogRedactor::context($in);
        $this->assertSame('[REDACTED]', $out['apikey'], 'apikey redacted');
        $this->assertSame('[REDACTED]', $out['authorization'], 'authorization redacted');
        $this->assertSame('[REDACTED]', $out['token'], 'token redacted');
    }

    private function test_recursive_redaction() {
        $in  = array('outer' => array('phone' => '525512345678', 'inner' => array('text' => 'hi there')));
        $out = EvoLogRedactor::context($in);
        $this->assertSame('********5678', $out['outer']['phone'], 'nested phone masked');
        $this->assertSame('[8 chars] hi there', $out['outer']['inner']['text'], 'nested text previewed');
    }

    private function test_case_insensitive_keys() {
        $in  = array('Phone' => '525512345678', 'API_KEY' => 'topsecret', 'TEXT' => 'hi');
        $out = EvoLogRedactor::context($in);
        $this->assertSame('********5678', $out['Phone'], 'Phone (mixed case) masked');
        $this->assertSame('[REDACTED]', $out['API_KEY'], 'API_KEY (uppercase) redacted');
        $this->assertSame('[2 chars] hi', $out['TEXT'], 'TEXT (uppercase) previewed');
    }

    private function test_realistic_log_context() {
        $in = array(
            'method' => 'POST',
            'url' => 'https://evo.example.com/message/sendText/inst',
            'payload' => array(
                'number' => '525512345678',
                'text' => 'Hola, su ticket #1234 ha sido recibido. Le atenderemos pronto.',
            ),
            'status' => 200,
        );
        $out = EvoLogRedactor::context($in);
        $this->assertSame('POST', $out['method'], 'method preserved');
        $this->assertSame(200, $out['status'], 'status preserved');
        $this->assertSame('********5678', $out['payload']['number'], 'payload.number masked');
        // The first 40 chars CAN appear (that's the preview), but the
        // truncated tail must not.
        if (strpos($out['payload']['text'], 'atenderemos') !== false) {
            $this->failed++;
            $this->failures[] = 'realistic log: full ticket text leaked instead of being truncated';
        } else {
            $this->passed++;
        }
        // And the preview must start with "[N chars] " marker.
        if (strpos($out['payload']['text'], '[') !== 0) {
            $this->failed++;
            $this->failures[] = 'realistic log: preview missing length marker prefix';
        } else {
            $this->passed++;
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new LogRedactorTest();
    $t->run();
    echo "OK\n";
}
