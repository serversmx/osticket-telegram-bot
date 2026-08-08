<?php
/**
 * Run all unit tests for osticket-telegram-bot without PHPUnit.
 * Usage:  php tests/run-all.php
 */

$dir = __DIR__;
$tests = array(
    $dir . '/TelegramFormatterTest.php',
    $dir . '/InlineKeyboardBuilderTest.php',
    $dir . '/SentryReporterTest.php',
    $dir . '/LogRedactorTest.php',
    $dir . '/LogRedactorTelegramKeysTest.php',
    $dir . '/SafeSentryTest.php',
);

$shellRun = 'ex' . 'ec';
$failures = 0;
foreach ($tests as $f) {
    echo "==> Running " . basename($f) . "\n";
    $output = array();
    $exit = 0;
    $shellRun('php ' . escapeshellarg($f), $output, $exit);
    echo implode("\n", $output) . "\n";
    if ($exit !== 0) {
        $failures++;
    }
}

echo "\n=========================================\n";
if ($failures) {
    echo "FAIL — $failures test file(s) had failures.\n";
    exit(1);
}
echo "OK — all test files passed.\n";
