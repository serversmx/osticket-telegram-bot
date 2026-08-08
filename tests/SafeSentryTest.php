<?php
/**
 * SafeSentryTest — verifies PII masking BEFORE anything reaches Sentry.
 *
 * The 2026-08-08 review flagged that raw supergroup ids (e.g.
 * -1003999130791) can leak into Sentry TAGS (searchable index) or into
 * stack-frame local vars. This test locks in the invariants:
 *   1. A raw signed supergroup id NEVER appears in the tags array.
 *   2. Any context key spelled loosely ('chat', 'chatId', 'chat_id',
 *      'chats', 'chat_ids') gets masked.
 *   3. Nested arrays are recursively masked.
 *   4. Message ids get a partial mask so correlation isn't easy but
 *      debugging is possible.
 */

require_once dirname(__DIR__) . '/plugin/lib/SafeSentry.php';

$assertions = 0;
$failures = 0;

function assertTrue($cond, $label) {
    global $assertions, $failures;
    $assertions++;
    if ($cond) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }
}
function assertEqual($expected, $actual, $label) {
    global $assertions, $failures;
    $assertions++;
    if ($expected === $actual) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

// ─── maskChatId ─────────────────────────────────────────────────────────
echo "\n== maskChatId ==\n";
assertEqual('***0791', TgSafeSentry::maskChatId(-1003999130791), 'negative supergroup id → last 4');
assertEqual('***4650', TgSafeSentry::maskChatId(11194650),        'positive user id → last 4');
assertEqual('***1234', TgSafeSentry::maskChatId('1234'),          'short id preserved as last-4');
assertEqual('***0123', TgSafeSentry::maskChatId(123),             'sub-4-digit id padded with zeros');
assertEqual('***',     TgSafeSentry::maskChatId(''),              'empty input → generic mask');

// ─── maskMessageId ──────────────────────────────────────────────────────
echo "\n== maskMessageId ==\n";
assertEqual('12..78', TgSafeSentry::maskMessageId(12345678), 'long msg id → 2..2');
assertEqual('42',     TgSafeSentry::maskMessageId(42),       'short msg id preserved');

// ─── tagsFor ────────────────────────────────────────────────────────────
echo "\n== tagsFor ==\n";
$tags = TgSafeSentry::tagsFor(array(
    'ticket_id'  => 91414,
    'chat_id'    => -1003999130791,
    'message_id' => 12345,
    'notif_type' => 'ticket.created',
    'error_kind' => 'gone',
));
assertEqual('91414',           $tags['ticket_id'],  'ticket_id copied raw (internal, non-PII)');
assertEqual('***0791',         $tags['chat_last4'], 'chat_id → chat_last4 with masking');
assertEqual('12..45',          $tags['message'],    'message_id → partial mask');
assertEqual('ticket.created',  $tags['notif_type'], 'notif_type passthrough');
assertEqual('gone',            $tags['error_kind'], 'error_kind passthrough');
// The critical invariant — no raw negative supergroup id anywhere.
$flat = json_encode($tags);
assertTrue(strpos($flat, '-1003999130791') === false, 'raw supergroup id NOT in tags JSON');
assertTrue(strpos($flat, '1003999130791')  === false, 'unsigned form of supergroup id NOT in tags JSON');

// ─── sanitizeContext ────────────────────────────────────────────────────
echo "\n== sanitizeContext ==\n";
$clean = TgSafeSentry::sanitizeContext(array(
    'ticket_id' => 91414,
    'chat_id'   => -1003999130791,
    'chatId'    => 11194650,           // loose casing
    'chat'      => '704356372',        // even looser key
    'chats'     => array(11194650, 704356372),
    'nested'    => array('chat_id' => -100, 'other' => 'safe'),
));
assertEqual('***0791', $clean['chat_id'], 'chat_id (canonical) masked');
assertEqual('***4650', $clean['chatId'],  'chatId (camel) masked');
assertEqual('***6372', $clean['chat'],    "'chat' loose key masked");
assertEqual('***4650', $clean['chats'][0],'chats[] array element masked');
assertEqual('***6372', $clean['chats'][1],'chats[] second element masked');
assertEqual('***0100', $clean['nested']['chat_id'], 'nested chat_id recursively masked');
assertEqual('safe',    $clean['nested']['other'],   'non-PII nested key untouched');

// ─── Regression: raw supergroup id NEVER appears in a sanitized payload ─
$flatCtx = json_encode($clean);
assertTrue(strpos($flatCtx, '-1003999130791') === false, 'raw signed supergroup id NOT in sanitized context');
assertTrue(strpos($flatCtx, '11194650')       === false, 'raw user chat id NOT in sanitized context');

echo "\n== Summary ==\n";
echo "  Assertions: $assertions\n";
echo "  Failures:   $failures\n";
if ($failures > 0) { exit(1); }
echo "  OK\n";
