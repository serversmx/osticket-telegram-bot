<?php
/**
 * Public webhook endpoint for inbound Telegram Updates.
 *
 * Bot setWebhook URL points here:
 *   https://<your-osticket>/include/plugins/telegram-bot/webhook.php
 *
 * Telegram POSTs an Update JSON to this script. We:
 *   1. Verify the `X-Telegram-Bot-Api-Secret-Token` header against the
 *      `webhook_secret_token` configured in the plugin admin.
 *   2. Parse the JSON body into an associative array.
 *   3. Bootstrap osTicket so plugins, signals, and DB are available.
 *   4. Dispatch to TelegramBotNotificationsPlugin::processUpdate().
 *   5. Reply 200 OK (Telegram retries on non-200).
 *
 * Constraints:
 *   - Always return 200 to Telegram on parse success — non-200 makes
 *     Telegram retry, which floods the log.
 *   - Never expose internal errors to the response body.
 *
 * @license GPL-2.0-or-later
 */

// 1. Bootstrap osTicket via main.inc.php — the canonical entry point.
// This handles config load, table-name constant definition, i18n,
// code load, and DB connect in the same order as every osTicket page.
// The plugin folder lives under <osticket>/include/plugins/telegram-bot/,
// so the osTicket root is three directories up.
$mainInc = __DIR__ . '/../../../main.inc.php';
if (!is_file($mainInc)) {
    http_response_code(500);
    error_log('[TelegramBot/webhook] osTicket main.inc.php not found at ' . $mainInc);
    exit;
}
require_once $mainInc;

if (!class_exists('PluginManager')) {
    http_response_code(500);
    error_log('[TelegramBot/webhook] PluginManager not available — bootstrap failed?');
    exit;
}

// 2. Locate the running plugin instance.
$plugin = null;
try {
    $pm = new PluginManager();
    $instances = $pm->allInstalled();
    foreach ($instances as $p) {
        if (method_exists($p, 'getName') && stripos($p->getName(), 'Telegram Bot Notifications') !== false) {
            $plugin = $p;
            break;
        }
    }
} catch (Exception $e) {
    error_log('[TelegramBot/webhook] Failed to enumerate plugins: ' . $e->getMessage());
}

if (!$plugin || !($plugin instanceof TelegramBotNotificationsPlugin)) {
    http_response_code(500);
    error_log('[TelegramBot/webhook] Plugin instance not found / not enabled.');
    exit;
}

// Side-load the active instance config. PluginManager::bootstrap() clears
// $plugin->config = null after running each plugin's instance bootstrap,
// so $plugin->getConfig() (without arg) here would return an empty
// default-namespaced config. We need to re-bind via an active instance.
$activeInstance = null;
if (method_exists($plugin, 'getActiveInstances')) {
    foreach ($plugin->getActiveInstances() as $inst) {
        $activeInstance = $inst;
        break;
    }
}
if (!$activeInstance) {
    http_response_code(500);
    error_log('[TelegramBot/webhook] Plugin has no active instance.');
    exit;
}
// FIX 2026-06-12: DO NOT name this $cfg. main.inc.php already set the global
// $cfg to osTicket's OsticketConfig, and webhook.php runs in file scope, so
// reusing the name overwrites it. Later, when a callback handler calls
// Ticket::assignToStaff() -> Staff::getName() -> new AgentsName(), the
// AgentsName constructor does `global $cfg; $cfg->getAgentNameFormat()` and
// crashes because TelegramBotNotificationsPluginConfig has no such method
// (Sentry TICKETS-TVPLUS-F).
$pluginCfg = $plugin->getConfig($activeInstance);

// 3. Verify the secret token. If the admin didn't set one, accept anyway
// (but warn in the log — strongly recommended to set one).
$configuredSecret = trim((string) $pluginCfg->get('webhook_secret_token'));
$gotSecret = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])
    ? (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']
    : '';

if ($configuredSecret !== '') {
    if (!hash_equals($configuredSecret, $gotSecret)) {
        http_response_code(401);
        error_log('[TelegramBot/webhook] Rejected request — secret token mismatch.');
        exit;
    }
} else {
    error_log('[TelegramBot/webhook] WARNING: webhook_secret_token is not set. Anyone who knows the URL can POST updates.');
}

// 4. Read body + parse JSON.
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(200);
    echo 'ok';
    exit;
}
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(200);  // Don't make Telegram retry malformed bodies.
    error_log('[TelegramBot/webhook] Malformed JSON body — ignoring.');
    echo 'ok';
    exit;
}

// 5. Dispatch to the plugin.
try {
    $plugin->processUpdate($update);
} catch (Exception $e) {
    error_log('[TelegramBot/webhook] processUpdate threw: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';
