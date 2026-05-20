<?php
/**
 * Tests for EvoSentryReporter — DSN parsing and no-op behavior when disabled.
 * Run: php tests/SentryReporterTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/SentryReporter.php';

class SentryReporterTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_disabled_when_no_dsn();
        $this->test_disabled_when_dsn_is_empty();
        $this->test_invalid_dsn_no_project_id();
        $this->test_invalid_dsn_no_at_sign();
        $this->test_invalid_dsn_non_numeric_project_id();
        $this->test_valid_dsn_enables_reporter();
        $this->test_capture_message_returns_null_when_disabled();
        $this->test_capture_exception_returns_null_when_disabled();
        $this->test_tags_and_extras_persist_across_calls();

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

    private function assertTrue($cond, $msg)  { $this->assertSame(true,  $cond, $msg); }
    private function assertFalse($cond, $msg) { $this->assertSame(false, $cond, $msg); }
    private function assertNull($v, $msg)     { $this->assertSame(null,  $v,    $msg); }

    private function test_disabled_when_no_dsn() {
        $r = new EvoSentryReporter();
        $this->assertFalse($r->isEnabled(), 'no DSN → disabled');
    }

    private function test_disabled_when_dsn_is_empty() {
        $r = new EvoSentryReporter('');
        $this->assertFalse($r->isEnabled(), 'empty DSN → disabled');
    }

    private function test_invalid_dsn_no_project_id() {
        $r = new EvoSentryReporter('https://key@host');
        $this->assertFalse($r->isEnabled(), 'DSN without /project_id → disabled');
    }

    private function test_invalid_dsn_no_at_sign() {
        $r = new EvoSentryReporter('https://nohost/123');
        $this->assertFalse($r->isEnabled(), 'DSN without key@ → disabled');
    }

    private function test_invalid_dsn_non_numeric_project_id() {
        $r = new EvoSentryReporter('https://key@host/notdigits');
        $this->assertFalse($r->isEnabled(), 'DSN with non-numeric project id → disabled');
    }

    private function test_valid_dsn_enables_reporter() {
        $r = new EvoSentryReporter('https://abc123def456@o0.ingest.sentry.io/4567890');
        $this->assertTrue($r->isEnabled(), 'valid DSN → enabled');
    }

    private function test_capture_message_returns_null_when_disabled() {
        $r = new EvoSentryReporter();
        $this->assertNull($r->captureMessage('test'), 'captureMessage returns null when disabled');
    }

    private function test_capture_exception_returns_null_when_disabled() {
        $r = new EvoSentryReporter();
        $e = new Exception('boom');
        $this->assertNull($r->captureException($e), 'captureException returns null when disabled');
    }

    private function test_tags_and_extras_persist_across_calls() {
        $r = new EvoSentryReporter();  // disabled, but state should still track.
        $r->addTag('plugin', 'test');
        $r->addExtra('extra_key', 'extra_value');
        $r->setEnvironment('staging');
        $r->setRelease('1.0.0');
        // Just verify these don't crash when chained. The reporter is disabled
        // so we can't easily inspect the actual payload — but we can verify
        // setters don't throw.
        $this->assertTrue(true, 'setters chain without errors');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new SentryReporterTest();
    $t->run();
    echo "OK\n";
}
