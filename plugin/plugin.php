<?php
/**
 * osTicket Telegram Bot Notifications - plugin metadata.
 *
 * @license GPL-2.0-or-later
 * @link    https://github.com/RenatoAscencio/osticket-telegram-bot
 */

return array(
    'id'          => 'tvplus:telegram-bot-notifications',
    'version'     => '0.2.0',
    'ost_version' => '1.17',
    'name'        => /* trans */ 'Telegram Bot Notifications',
    'author'      => 'Renato Ascencio',
    'description' => /* trans */ 'Sends Telegram Bot notifications to end-users and admins on ticket lifecycle events with inline buttons. Customers link their Telegram via a one-shot bot deep-link (/start <token>) — after ticket creation they receive an email with a "Link Telegram" button. Per-event × per-audience toggles, MarkdownV2/HTML templates, optional Sentry integration. Notification preferences are managed from the staff page <code>/scp/link-telegram.php</code>.',
    'url'         => 'https://github.com/RenatoAscencio/osticket-telegram-bot',
    'plugin'      => 'telegram.php:TelegramBotNotificationsPlugin',
);
