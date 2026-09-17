<?php
/**
 * InviteGuardTest — the post-ticket invitation email must only reach humans.
 *
 * Regression for the 2026-09 Amazon loop: a return notification from
 * return@amazon.com (via Amazon SES, no Auto-Submitted header) opened a
 * ticket, the plugin mailed it the invite, Amazon's auto-responder answered
 * from nobody@bounces.amazon.com and that answer opened another ticket.
 * Header fixtures below mirror the real messages with identifiers removed.
 *
 * Run: php tests/InviteGuardTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/InviteGuard.php';

$assertions = 0;
$failures = 0;

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

function headers(array $lines) {
    return implode("\r\n", $lines) . "\r\n";
}

$amazonReturn = headers(array(
    'Return-Path: <20260916161856abc-C11EXAMPLE@bounces.amazon.com>',
    'Delivered-To: sales@example.mx',
    'From: "return@amazon.com" <return@amazon.com>',
    'To: ventas@example.mx',
    'Subject: =?UTF-8?B?VHUgZGV2b2x1Y2nDs24=?=',
    'Content-Type: multipart/alternative;',
    "\tboundary=\"----=_Part_1\"",
    'X-AMAZON-MAIL-RELAY-TYPE: notification',
    'Bounces-to: 20260916161856abc-C11EXAMPLE@bounces.amazon.com',
    'Feedback-ID: 883122550::1.us-east-1.EXAMPLE=:AmazonSES',
    'X-SES-Outgoing: 2026.09.16-54.240.13.112',
));

$amazonAutoReply = headers(array(
    'Return-Path: <prvs=7127c0e8a=mailnull@amazon.com>',
    'Subject: Re: =?UTF-8?Q?Vincula=20tu=20Telegram=20para=20recibir=20?=',
    'In-Reply-To: <EXAMPLE-alert@example.mx>',
    'To: =?utf-8?Q?Soporte?= <alert@example.mx>',
    'X-Amazon-Auto-Reply: true',
    'From: "Amazon.com" <nobody@bounces.amazon.com>',
));

$paypal = headers(array(
    'Return-Path: <service@updates.paypal.com>',
    'From: Notification via PayPal <service@updates.paypal.com>',
    'To: Example <pagos@example.mx>',
    'X-PP-Email-transmission-Id: 00000000-0000-0000-0000-000000000000',
    'X-Email-Type-Id: RT004167',
));

$giftCard = headers(array(
    'Return-Path: <2026EXAMPLE@bounces.gift-cards.amazon.com>',
    'From: Amazon <do-not-reply@gift-cards.amazon.com>',
    'Auto-Submitted: auto-generated',
));

$human = headers(array(
    'Return-Path: <cliente.ejemplo@gmail.com>',
    'Delivered-To: sales@example.mx',
    'From: Cliente Ejemplo <cliente.ejemplo@gmail.com>',
    'To: ventas@example.mx',
    'Subject: Ayuda para descargar la app',
    'MIME-Version: 1.0',
    'Content-Type: multipart/alternative; boundary="000000000000abc"',
    'X-Spam-Status: No, score=0.8',
    'X-Spam-Score: 8',
    'X-Spam-Flag: NO',
));

$humanIphone = headers(array(
    'Return-Path: <cliente@yahoo.com.mx>',
    'From: Cliente <cliente@yahoo.com.mx>',
    'To: soporte@example.mx',
    'X-Mailer: iPhone Mail (23G71)',
    'Auto-Submitted: no',
));

$selfAddressedSpam = headers(array(
    'Return-Path: <inquiry@spam.example>',
    'From: "Andy" <inquiry@spam.example>',
    'To: "Andy" <inquiry@spam.example>',
    'Subject: Re:Transferencia de Pago Devuelta',
));

// ─── The 2026-09 Amazon loop ────────────────────────────────────────────
echo "\n== Amazon loop ==\n";
assertEqual('automated-mailbox', TgInviteGuard::skipReason('return@amazon.com', $amazonReturn, array('subject' => 'Tu devolución de 2x...')),
    'return@amazon.com notification is skipped');
assertEqual('header:feedback-id', TgInviteGuard::headerReason(TgInviteGuard::parseHeaders($amazonReturn)),
    'headers alone flag the Amazon notification');
assertEqual('header:x-ses-outgoing', TgInviteGuard::headerReason(TgInviteGuard::parseHeaders(headers(array(
    'From: "Tienda" <ventas@tienda.example>',
    'X-SES-Outgoing: 2026.09.16-54.240.13.112',
)))), 'X-SES-Outgoing alone is enough');
assertEqual('reply-to-invite', TgInviteGuard::skipReason('nobody@bounces.amazon.com', $amazonAutoReply,
    array('subject' => 'Re: Vincula tu Telegram para recibir actualizaciones de tu ticket #532996')),
    'auto-reply to our own invite is skipped');
assertEqual('header:x-amazon-auto-reply', TgInviteGuard::headerReason(TgInviteGuard::parseHeaders($amazonAutoReply)),
    'X-Amazon-Auto-Reply is recognised');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('store-news@amazon.com', '', array('subject' => 'Ofertas')),
    'store-news@ is a robot mailbox');
assertEqual('brand-domain', TgInviteGuard::skipReason('ventas@amazon.com'),
    'amazon.com is a brand domain even without headers (e.g. web form)');
assertEqual('brand-domain', TgInviteGuard::skipReason('hola@marketplace.amazon.com.mx'),
    'amazon.com.mx subdomains are a brand domain');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('do-not-reply@gift-cards.amazon.com', $giftCard),
    'gift card notification is skipped');

// ─── Other automated senders ────────────────────────────────────────────
echo "\n== Automated senders ==\n";
assertEqual('automated-subdomain', TgInviteGuard::skipReason('service@updates.paypal.com', $paypal),
    'PayPal updates subdomain is skipped');
assertEqual('brand-domain', TgInviteGuard::skipReason('service@paypal.com.mx'),
    'paypal.com.mx is a brand domain');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('wordpress@example.org'),
    'wordpress@ is a robot');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('MAILER-DAEMON@mx.example.com'),
    'mailer-daemon (any case)');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('billing-noreply@shop.example'),
    'noreply as a suffix token');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('noreply+abc@shop.example'),
    'noreply with +tag');
assertEqual('automated-subdomain', TgInviteGuard::skipReason('hello@bounces.shop.example'),
    'bounces label anywhere in the domain');
assertEqual('self-addressed', TgInviteGuard::skipReason('inquiry@spam.example', $selfAddressedSpam),
    'Bcc blast addressed to the sender itself');
assertEqual('own-domain', TgInviteGuard::skipReason('Care@Example.MX', '', array('own_domains' => array('example.mx'))),
    'helpdesk own domain (case-insensitive)');
assertEqual('blocked-domain', TgInviteGuard::skipReason('ventas@proveedor.example', '', array('skip_list' => array('proveedor.example'))),
    'admin-configured extra domain');
assertEqual('blocked-domain', TgInviteGuard::skipReason('a@mx.proveedor.example', '', array('skip_list' => array('proveedor.example'))),
    'extra domain covers subdomains');
assertEqual('invalid-email', TgInviteGuard::skipReason('not-an-email'),
    'invalid address');

// ─── Header signals ─────────────────────────────────────────────────────
echo "\n== Header signals ==\n";
$cases = array(
    'header:precedence'               => array('Precedence: bulk'),
    'header:list-unsubscribe'         => array('List-Unsubscribe: <mailto:unsub@list.example>'),
    'header:feedback-id'              => array('Feedback-ID: 1:campaign:sender'),
    'header:x-auto-response-suppress' => array('X-Auto-Response-Suppress: All'),
    'header:x-autoreply'              => array('X-Autoreply: yes'),
    'header:auto-submitted'           => array('Auto-Submitted: auto-replied'),
    'spam'                            => array('X-Spam-Flag: YES'),
    'header:x-failed-recipients'      => array('X-Failed-Recipients: someone@example.com'),
    'bounce'                          => array('Return-Path: <>'),
    'spam'                            => array('X-Spam-Status: Yes, score=9.1'),
);
foreach ($cases as $expected => $lines) {
    assertEqual($expected, TgInviteGuard::headerReason(TgInviteGuard::parseHeaders(headers($lines))),
        $lines[0]);
}
assertEqual('bounce', TgInviteGuard::headerReason(TgInviteGuard::parseHeaders(headers(array(
    'Content-Type: multipart/report;',
    ' report-type=delivery-status; boundary="x"',
)))), 'folded multipart/report DSN');
assertEqual('header:list-id', TgInviteGuard::skipReason('persona@empresa.example',
    array('List-Id' => 'Grupo <grupo.empresa.example>')),
    'pre-parsed header arrays are accepted (any key case)');

// ─── Humans get the invitation ──────────────────────────────────────────
echo "\n== Humans ==\n";
assertEqual(null, TgInviteGuard::skipReason('cliente.ejemplo@gmail.com', $human, array('subject' => 'Ayuda para descargar la app', 'own_domains' => array('example.mx'))),
    'gmail customer writing to support');
assertEqual(null, TgInviteGuard::skipReason('cliente@yahoo.com.mx', $humanIphone, array('subject' => 'Pago realizado', 'own_domains' => array('example.mx'))),
    'Auto-Submitted: no is a human message');
assertEqual(null, TgInviteGuard::skipReason('persona@hotmail.com', '', array('subject' => 'Activacion 1 mes - Pago Manual')),
    'web/API ticket without headers');
assertEqual(null, TgInviteGuard::skipReason('rperez@prodigy.net.mx'),
    '3-label ISP domain is not an automated subdomain');
assertEqual(null, TgInviteGuard::skipReason('alumno@email.universidad.example'),
    'email.* subdomain is not treated as automated (student addresses)');
assertEqual(null, TgInviteGuard::skipReason('newsom.carlos@gmail.com'),
    'local part merely starting with "news" is fine');
assertEqual(null, TgInviteGuard::skipReason('updatesmx@gmail.com'),
    'local part merely starting with "updates" is fine');
assertEqual(null, TgInviteGuard::skipReason('amigo@notamazon.com'),
    'brand match requires a label boundary');
assertEqual(null, TgInviteGuard::skipReason('persona@empresa.example', headers(array(
    'From: Persona <persona@empresa.example>',
    'To: Persona <persona@empresa.example>, ventas@example.mx',
))), 'sender copied on To together with support is not self-addressed');

// ─── parseSkipList ──────────────────────────────────────────────────────
echo "\n== parseSkipList ==\n";
assertEqual(array('proveedor.example', 'otra.com.mx', 'avisos@banco.example'),
    TgInviteGuard::parseSkipList("Proveedor.example\n@otra.com.mx, invalid, proveedor.example ; Avisos@Banco.example"),
    'normalizes, dedupes, keeps addresses and drops invalid entries');
assertEqual(array(), TgInviteGuard::parseSkipList(''), 'empty list');
assertEqual('blocked-address', TgInviteGuard::skipReason('pagos@banco.example', '', array('skip_list' => array('pagos@banco.example'))),
    'full address in skip list');
assertEqual(null, TgInviteGuard::skipReason('ejecutivo@banco.example', '', array('skip_list' => array('pagos@banco.example'))),
    'address entry does not block the whole domain');

// ─── Verification round 2026-09-17 ──────────────────────────────────────
echo "\n== Local parts ==\n";
assertEqual(null, TgInviteGuard::skipReason('system.aikors@hotmail.es'), 'role word + suffix on freemail is a person');
assertEqual(null, TgInviteGuard::skipReason('news.carlos@gmail.com'), 'news.<name> is a person');
assertEqual(null, TgInviteGuard::skipReason('marketing.ruiz@hotmail.com'), 'marketing.<name> is a person');
assertEqual('automated-mailbox', TgInviteGuard::skipReason('notifications+mx@shop.example'), 'role mailbox with +tag');
foreach (array('no_reply@x.example', 'no.reply@x.example', 'no-responder@x.example', 'noresponder@x.example',
               'cuenta.no_responder@x.example', 'notificaciones@banco.example', 'alertas@banco.example',
               'avisos@banco.example', 'nobody@x.example', 'mailnull@x.example') as $robot) {
    assertEqual('automated-mailbox', TgInviteGuard::skipReason($robot), $robot);
}
assertEqual('automated-subdomain', TgInviteGuard::skipReason('clientes@notificaciones.banco.example'), 'notificaciones.* subdomain');
assertEqual('automated-subdomain', TgInviteGuard::skipReason('hsbc@reply1.banco.example'), 'reply1.* subdomain');

echo "\n== Spam patterns ==\n";
assertEqual('mirrored-subdomain', TgInviteGuard::skipReason('zgfmg@zgfmg.cqxbu.example'), 'local part mirrored as subdomain');
assertEqual(null, TgInviteGuard::skipReason('coval@coval.com.sv'), 'company whose name equals its org domain');
assertEqual('invisible-characters', TgInviteGuard::skipReason('ventas@tienda.example', '', array('name' => "A\u{200B}mazon")),
    'zero-width characters in display name');
assertEqual('invisible-characters', TgInviteGuard::skipReason('ventas@tienda.example', '', array('subject' => "Tu pedido de Am\u{FEFF}azon")),
    'zero-width characters in subject');
assertEqual('invalid-email', TgInviteGuard::skipReason('softaculous@.missing-host-name.'), 'malformed domain');

echo "\n== Auto-replies ==\n";
foreach (array('Automatic reply: Ticket de Soporte Abierto [#1]', 'Respuesta automática: Pago', 'RE: Vincula  tu Telegram para recibir',
               'Fuera de la oficina: Pedido', 'Out of Office: Order') as $subj) {
    assertEqual(true, TgInviteGuard::skipReason('persona@empresa.example', '', array('subject' => $subj)) !== null, $subj);
}
assertEqual('reply-to-invite', TgInviteGuard::skipReason('support@helpdesk.example', headers(array(
    'From: Support <support@helpdesk.example>',
    'Subject: [Ticket #4411] We received your request',
    'In-Reply-To: <BPUcNOT-NWE1o-AAAAAAAAAAAAAAAAP/1Mq58v-alert@example.mx>',
)), array('invite_sender' => 'alert@example.mx')), 'answer to our invitation Message-ID with a changed subject');
assertEqual(null, TgInviteGuard::skipReason('cliente.ejemplo@gmail.com', headers(array(
    'From: Cliente <cliente.ejemplo@gmail.com>',
    'In-Reply-To: <BPUcNOT-abcde-QmFzZTY0VGFn-sales@example.mx>',
)), array('invite_sender' => 'alert@example.mx')), 'reply to a regular ticket email from another mailbox');
assertEqual('header:x-ms-exchange-inbox-rules-loop', TgInviteGuard::headerReason(TgInviteGuard::parseHeaders(headers(array(
    'X-MS-Exchange-Inbox-Rules-Loop: persona@empresa.example',
)))), 'Exchange inbox-rule reply');

echo "\n== Personal mailbox providers ==\n";
assertEqual(null, TgInviteGuard::skipReason('web@empresa.example', headers(array(
    'From: German <web@empresa.example>',
    'To: soporte@example.mx',
    'X-Mailer: Amazon WorkMail',
    'X-WM-Sent-Timestamp: 1739600000',
    'Feedback-ID: 1.us-east-1.EXAMPLE=:AmazonSES',
    'X-SES-Outgoing: 2025.02.15-54.240.0.1',
))), 'Amazon WorkMail person (SES headers)');
assertEqual(null, TgInviteGuard::skipReason('persona@protonmail.com', headers(array(
    'From: Persona <persona@protonmail.com>',
    'To: soporte@example.mx',
    'Feedback-ID: 0000000000000000000000000000==:Ext:ProtonMail',
))), 'ProtonMail person (Feedback-ID)');
assertEqual('header:list-unsubscribe', TgInviteGuard::skipReason('ventas@empresa.example', headers(array(
    'X-Mailer: Amazon WorkMail',
    'List-Unsubscribe: <https://empresa.example/unsub>',
))), 'WorkMail exemption only covers Feedback-ID / X-SES-Outgoing');

echo "\n== Spam verdict ==\n";
assertEqual(null, TgInviteGuard::headerReason(TgInviteGuard::parseHeaders(headers(array(
    'X-Spam-Status: Yes, score=20',
    'X-Spam-Status: No, score=0.8',
)))), 'only the last (our MTA) spam verdict counts');

echo "\n== SPF / DKIM (SpamAssassin report) ==\n";
function report(array $rules) {
    $lines = array('X-Ham-Report: Spam detection software, running on the system "mx.example",',
        ' has NOT identified this incoming email as spam.',
        ' Content preview:  0.0 SPF_FAIL is quoted in the body and must be ignored',
        ' Content analysis details:   (0.2 points, 5.0 required)',
        '  pts rule name              description',
        ' ---- ---------------------- --------------------------------------------------');
    foreach ($rules as $r) { $lines[] = '  0.0 ' . str_pad($r, 22) . ' description of the rule'; }
    return $lines;
}
$gmailHuman = headers(array_merge(array('Return-Path: <cliente.ejemplo@gmail.com>', 'From: <cliente.ejemplo@gmail.com>'),
    report(array('SPF_PASS', 'DKIM_SIGNED', 'DKIM_VALID_AU', 'DKIM_VALID', 'FREEMAIL_FROM'))));
assertEqual(null, TgInviteGuard::skipReason('cliente.ejemplo@gmail.com', $gmailHuman), 'DKIM aligned human');
$forged = headers(array_merge(array('Return-Path: <compras@proveedor.example>', 'From: <compras@proveedor.example>'),
    report(array('SPF_SOFTFAIL', 'HTML_MESSAGE'))));
assertEqual('unauthenticated-sender', TgInviteGuard::skipReason('compras@proveedor.example', $forged), 'SPF softfail and no DKIM (forged From)');
$spfOnly = headers(array_merge(array('Return-Path: <info@pyme.com.mx>', 'From: <info@pyme.com.mx>'),
    report(array('SPF_PASS', 'SPF_HELO_NONE'))));
assertEqual(null, TgInviteGuard::skipReason('info@pyme.com.mx', $spfOnly), 'SPF pass for the same organizational domain, no DKIM');
$quotedOnly = headers(array_merge(array('Return-Path: <a@empresa.example>'), report(array('HTML_MESSAGE'))));
assertEqual(null, TgInviteGuard::skipReason('a@empresa.example', $quotedOnly), 'rule names in the content preview are ignored; no SPF result fails open');
$inconclusive = headers(array_merge(array('Return-Path: <a@empresa.example>'), report(array('SPF_NONE', 'DKIM_SIGNED'))));
assertEqual(null, TgInviteGuard::skipReason('a@empresa.example', $inconclusive), 'DKIM signed but not evaluated fails open');
assertEqual(null, TgInviteGuard::skipReason('a@empresa.example', headers(array('From: <a@empresa.example>'))), 'no report fails open');
assertEqual('empresa.com.mx', TgInviteGuard::orgDomain('mail.empresa.com.mx'), 'orgDomain keeps com.mx');
assertEqual('example.com', TgInviteGuard::orgDomain('a.b.example.com'), 'orgDomain of a generic TLD');

echo "\n-- $assertions assertions, $failures failures --\n";
exit($failures ? 1 : 0);
