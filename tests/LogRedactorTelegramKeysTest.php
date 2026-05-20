<?php
/**
 * Telegram-specific tests for EvoLogRedactor — covers the additional keys
 * this plugin contributes (chat_id, bot_token, secret_token, etc.).
 *
 * Run: php tests/LogRedactorTelegramKeysTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/LogRedactor.php';

class LogRedactorTelegramKeysTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_chat_id_masked();
        $this->test_chat_ids_masked();
        $this->test_negative_group_chat_id_masked();
        $this->test_bot_token_redacted();
        $this->test_secret_token_redacted();
        $this->test_webhook_secret_token_redacted();
        $this->test_realistic_telegram_log();

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

    private function test_chat_id_masked() {
        $out = EvoLogRedactor::context(array('chat_id' => '1234567890'));
        $this->assertSame('******7890', $out['chat_id'], 'chat_id masked to last-4');
    }

    private function test_chat_ids_masked() {
        $out = EvoLogRedactor::context(array('chat_ids' => array('1234567890', '987654321')));
        $this->assertSame(array('******7890', '*****4321'), $out['chat_ids'], 'chat_ids list masked');
    }

    private function test_negative_group_chat_id_masked() {
        $out = EvoLogRedactor::context(array('chat_id' => '-1001234567890'));
        // 14 chars total — keep last 4.
        $this->assertSame('**********7890', $out['chat_id'], 'negative group chat_id masked');
    }

    private function test_bot_token_redacted() {
        $out = EvoLogRedactor::context(array('bot_token' => '123456789:AAEXAMPLE'));
        $this->assertSame('[REDACTED]', $out['bot_token'], 'bot_token redacted');
    }

    private function test_secret_token_redacted() {
        $out = EvoLogRedactor::context(array('secret_token' => 'sometopsecret'));
        $this->assertSame('[REDACTED]', $out['secret_token'], 'secret_token redacted');
    }

    private function test_webhook_secret_token_redacted() {
        $out = EvoLogRedactor::context(array('webhook_secret_token' => 'abc123def456'));
        $this->assertSame('[REDACTED]', $out['webhook_secret_token'], 'webhook_secret_token redacted');
    }

    private function test_realistic_telegram_log() {
        $in = array(
            'method' => 'sendMessage',
            'payload' => array(
                'chat_id' => 1234567890,
                'text' => 'Your ticket #1234 has a new reply from staff.',
                'parse_mode' => 'MarkdownV2',
            ),
            'bot_token' => '123456789:AAEX',
        );
        $out = EvoLogRedactor::context($in);
        $this->assertSame('sendMessage', $out['method'], 'method preserved');
        $this->assertSame('MarkdownV2', $out['payload']['parse_mode'], 'parse_mode preserved');
        $this->assertSame('[REDACTED]', $out['bot_token'], 'bot_token redacted');
        $this->assertSame('******7890', $out['payload']['chat_id'], 'chat_id masked');
        // Text should be previewed, not full
        if (strpos($out['payload']['text'], 'reply from staff') !== false) {
            $this->failed++;
            $this->failures[] = 'full ticket text leaked instead of being truncated';
        } else {
            $this->passed++;
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new LogRedactorTelegramKeysTest();
    $t->run();
    echo "OK\n";
}
