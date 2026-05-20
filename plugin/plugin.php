<?php
/**
 * osTicket Telegram Bot Notifications - plugin metadata.
 *
 * @license GPL-2.0-or-later
 * @link    https://github.com/RenatoAscencio/osticket-telegram-bot
 */

return array(
    'id'          => 'tvplus:telegram-bot-notifications',
    'version'     => '0.1.0',
    'ost_version' => '1.17',
    'name'        => /* trans */ 'Telegram Bot Notifications',
    'author'      => 'Renato Ascencio',
    'description' => /* trans */ 'Sends Telegram Bot notifications to end-users and admins on ticket lifecycle events with inline buttons. Customers link their Telegram via a bot deep-link (/start <token>). Per-event × per-audience toggles, MarkdownV2/HTML templates, opt-in support, optional Sentry integration.',
    'url'         => 'https://github.com/RenatoAscencio/osticket-telegram-bot',
    'plugin'      => 'telegram.php:TelegramBotNotificationsPlugin',
);
