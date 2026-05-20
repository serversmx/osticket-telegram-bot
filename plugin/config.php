<?php
/**
 * Admin configuration UI for the Telegram Bot Notifications plugin.
 *
 * Only instance-level settings live here:
 *   - Bot credentials / HTTP
 *   - Webhook (URL + secret)
 *   - Sentry
 *   - Debug
 *
 * All notification preferences (recipients, customer opt-in / linking flow,
 * per-event matrix, parse mode, templates, inline buttons, pacing) are
 * managed from `<osticket>/scp/link-telegram.php` (the staff-facing tabs).
 * Defaults for those keys live in `TelegramBotNotificationsPlugin::prefDefaults()`
 * so a brand-new install still has working notifications before an admin
 * opens the staff page.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class TelegramBotNotificationsPluginConfig extends PluginConfig {

    /**
     * Inline CSS injection so the plugin admin page has reasonable padding.
     */
    private static function styleInjection() {
        return '<style>'
            . '#pluginInstanceForm,'
            . '.plugin-config form,'
            . 'form.plugin-config { padding: 20px 28px 24px 28px; }'
            . '#pluginInstanceForm table.form_table th,'
            . '.plugin-config form table.form_table th { padding: 18px 0 8px 0; }'
            . '#pluginInstanceForm table.form_table td.label,'
            . '.plugin-config form table.form_table td.label { padding: 8px 16px 8px 4px; vertical-align: top; }'
            . '#pluginInstanceForm table.form_table td:not(.label),'
            . '.plugin-config form table.form_table td:not(.label) { padding: 8px 4px; }'
            . '#pluginInstanceForm .section-break,'
            . '.plugin-config .section-break { margin-top: 18px; border-top: 1px solid #e3e3e3; padding-top: 12px; }'
            . '#pluginInstanceForm .section-break:first-of-type,'
            . '.plugin-config .section-break:first-of-type { border-top: 0; margin-top: 0; }'
            . '#pluginInstanceForm .section-break h3,'
            . '.plugin-config .section-break h3 { margin: 0 0 6px 0; font-size: 1.05em; }'
            . '#pluginInstanceForm .section-break em,'
            . '.plugin-config .section-break em { display: block; color: #666; font-style: italic; margin-bottom: 8px; max-width: 880px; }'
            . '</style>';
    }

    function getOptions() {
        return array(

            '_styles' => new FreeTextField(array(
                'configuration' => array('content' => self::styleInjection()),
            )),

            '_notice' => new FreeTextField(array(
                'configuration' => array('content' =>
                    '<div style="padding:14px 18px;background:rgba(37,99,235,0.08);'
                    . 'border-left:4px solid #2563eb;border-radius:6px;margin:0 0 18px;'
                    . 'font-size:0.95em;">'
                    . '<strong>💡 Esta página es solo para configuración de instancia</strong> '
                    . '(bot, webhook, Sentry, debug).<br>'
                    . 'Las <strong>preferencias de notificación</strong> '
                    . '(destinatarios, vinculación de clientes, eventos, plantillas, formato, botones) '
                    . 'se gestionan desde el panel staff: '
                    . '<a href="../scp/link-telegram.php" style="color:#2563eb;'
                    . 'text-decoration:none;font-weight:600;">'
                    . 'Applications → Telegram</a>.'
                    . '</div>'
                ),
            )),

            // ─── Bot credentials ─────────────────────────────────────────────
            'sec_bot' => new SectionBreakField(array(
                'label' => '🤖  Telegram Bot — Connection',
                'hint'  => 'Create a bot via @BotFather on Telegram and paste its token below. After saving, the plugin will call getMe() to verify the token. The bot username (e.g. @MyBot) is needed for the customer linking deep-link.',
            )),

            'bot_token' => new PasswordField(array(
                'label'    => 'Bot token',
                'required' => true,
                'configuration' => array('size' => 70, 'length' => 200),
                'hint'     => 'Looks like 123456789:AAH... — get it from @BotFather. Masked once saved; leave blank to keep the existing value when editing other fields.',
            )),
            'bot_username' => new TextboxField(array(
                'label'    => 'Bot username',
                'required' => true,
                'configuration' => array('size' => 40, 'length' => 64),
                'hint'     => 'Without the @ prefix. Example: MyCompanySupportBot. Used to build the t.me/<bot>?start=<token> linking links.',
            )),
            'bot_api_base_url' => new TextboxField(array(
                'label'   => 'Bot API base URL (advanced)',
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'    => 'Defaults to https://api.telegram.org. Override only if you run a local Bot API server (https://github.com/tdlib/telegram-bot-api).',
            )),
            'verify_ssl' => new BooleanField(array(
                'label'   => 'Verify SSL certificate',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Recommended ON in production. Disable only when targeting a local Bot API server with a self-signed cert.',
                ),
            )),
            'http_timeout' => new TextboxField(array(
                'label'   => 'HTTP timeout (seconds)',
                'default' => '15',
                'configuration' => array('size' => 6, 'length' => 4),
            )),
            'http_max_attempts' => new TextboxField(array(
                'label'   => 'Max HTTP attempts (incl. retries)',
                'default' => '3',
                'configuration' => array('size' => 4, 'length' => 2),
                'hint'    => 'How many times to attempt each Bot API call when the response is a transient failure (network error, 429, 5xx). Backs off exponentially and honors Telegram\'s retry_after / Retry-After header. 1 disables retries.',
            )),

            // ─── Webhook ─────────────────────────────────────────────────────
            'sec_webhook' => new SectionBreakField(array(
                'label' => '🌐  Webhook (for inbound bot updates)',
                'hint'  => 'Configure the bot to call your osTicket install when users send /start, /unlink, etc. Required for deep-link linking. After saving, use the /tg-set-webhook command.',
            )),

            'webhook_public_url' => new TextboxField(array(
                'label'   => 'Public webhook URL',
                'configuration' => array('size' => 80, 'length' => 300),
                'hint'    => 'Full URL to webhook.php. Example: https://tickets.example.com/include/plugins/telegram-bot/webhook.php',
            )),
            'webhook_secret_token' => new PasswordField(array(
                'label'   => 'Webhook secret token',
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'    => 'Sent by Telegram in the X-Telegram-Bot-Api-Secret-Token header. Recommended: 32+ random characters (alphanumeric, _, -). Generate with: openssl rand -hex 24.',
            )),

            // ─── Sentry (optional) ───────────────────────────────────────────
            'sec_sentry' => new SectionBreakField(array(
                'label' => '🛡️  Sentry — error reporting (optional)',
                'hint'  => 'Lightweight Sentry integration. Leave DSN blank to disable.',
            )),

            'sentry_dsn' => new PasswordField(array(
                'label'   => 'Sentry DSN',
                'configuration' => array('size' => 80, 'length' => 300),
                'hint'    => 'Format: https://<key>@<host>/<project_id>',
            )),
            'sentry_environment' => new TextboxField(array(
                'label'   => 'Sentry environment',
                'default' => 'production',
                'configuration' => array('size' => 20, 'length' => 40),
            )),
            'sentry_capture_global' => new BooleanField(array(
                'label'   => 'Also capture global PHP errors',
                'default' => false,
            )),

            // ─── Debug ───────────────────────────────────────────────────────
            'sec_debug' => new SectionBreakField(array(
                'label' => '🐛  Debug',
            )),

            'debug_mode' => new BooleanField(array(
                'label'   => 'Verbose logging',
                'default' => false,
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        if (isset($config['bot_token'])) {
            $t = trim($config['bot_token']);
            if ($t !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]+$/', $t)) {
                $errors['bot_token'] = 'Bot token format looks invalid. Expected: NNNNNNNN:AA...';
                return false;
            }
        }
        if (isset($config['bot_username'])) {
            $u = ltrim(trim($config['bot_username']), '@');
            if ($u !== '' && !preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) {
                $errors['bot_username'] = 'Bot username must be 3–32 chars, letters/numbers/underscore only.';
                return false;
            }
            $config['bot_username'] = $u;
        }
        if (isset($config['bot_api_base_url'])) {
            $u = trim($config['bot_api_base_url']);
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $errors['bot_api_base_url'] = 'Bot API URL must start with http:// or https://.';
                return false;
            }
            $config['bot_api_base_url'] = rtrim($u, '/');
        }
        if (isset($config['webhook_public_url'])) {
            $u = trim($config['webhook_public_url']);
            if ($u !== '' && !preg_match('#^https://#i', $u)) {
                $errors['webhook_public_url'] = 'Webhook URL must be HTTPS (Telegram refuses plain HTTP).';
                return false;
            }
            $config['webhook_public_url'] = $u;
        }
        if (isset($config['sentry_dsn'])) {
            $dsn = trim($config['sentry_dsn']);
            if ($dsn !== '' && !preg_match('#^https?://[^@]+@[^/]+/\d+$#', $dsn)) {
                $errors['sentry_dsn'] = 'Sentry DSN looks invalid.';
                return false;
            }
            $config['sentry_dsn'] = $dsn;
        }
        return true;
    }
}
