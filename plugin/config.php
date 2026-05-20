<?php
/**
 * Admin configuration UI for the Telegram Bot Notifications plugin.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class TelegramBotNotificationsPluginConfig extends PluginConfig {

    /** Plain-text textarea (no Redactor / WYSIWYG). */
    private static function plainTextarea($rows = 6, $cols = 60) {
        return array('html' => false, 'rows' => $rows, 'cols' => $cols);
    }

    /**
     * Inline CSS injection so the plugin admin page has reasonable padding.
     * Same approach as osticket-evolution-api.
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

            // ─── Master recipient toggles ───────────────────────────────────
            'sec_recipients' => new SectionBreakField(array(
                'label' => '👥  Recipients — master switches',
                'hint'  => 'These are kill-switches that apply to every event. Per-event toggles only fire when the matching master switch is on.',
            )),

            'notify_clients' => new BooleanField(array(
                'label'   => 'Notify customers (end users)',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Send notifications to customers who have linked their Telegram account.',
                ),
            )),
            'notify_admins' => new BooleanField(array(
                'label'   => 'Notify staff/admins',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Send a copy of every enabled event to the admin chat(s) below.',
                ),
            )),
            'admin_chat_ids' => new TextareaField(array(
                'label'    => 'Admin Telegram chat IDs',
                'configuration' => self::plainTextarea(3, 60),
                'hint'     => 'One per line. A chat ID can be a positive integer (private user), a negative integer prefixed with -100 (supergroup or channel), or any chat the bot has been added to. Get IDs with @userinfobot. Example: -1001234567890',
            )),

            // ─── User opt-in & linking ──────────────────────────────────────
            'sec_optin' => new SectionBreakField(array(
                'label' => '🙋  Customer opt-in & linking',
                'hint'  => 'How customers connect their Telegram account. Either method may be used; both can be enabled simultaneously. See docs/user-linking.md.',
            )),

            'allow_deeplink_linking' => new BooleanField(array(
                'label'   => 'Enable bot deep-link linking',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Show a "Link Telegram" button on the customer\'s osTicket profile. Clicking opens t.me/<bot>?start=<token>; the bot replies "Linked!" once the token is consumed. Requires the webhook configured below.',
                ),
            )),
            'allow_manual_chat_id' => new BooleanField(array(
                'label'   => 'Allow manual chat_id entry',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Read the customer\'s chat_id from a custom field on the Contact Information form (variable name below). Works without a webhook.',
                ),
            )),
            'manual_chat_id_field_variable' => new TextboxField(array(
                'label'    => 'Manual chat_id field variable name',
                'default'  => 'telegram_chat_id',
                'configuration' => array('size' => 40, 'length' => 80),
                'hint'     => 'The "Variable Name" on the Contact Information form field that holds the chat_id. Used only when manual entry is enabled.',
            )),
            'respect_user_opt_in' => new BooleanField(array(
                'label'   => 'Respect customer opt-in preference',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Look up an opt-in checkbox on the Contact Information form. Skip the send when the customer has opted out.',
                ),
            )),
            'opt_in_field_variable' => new TextboxField(array(
                'label'    => 'Opt-in field variable name',
                'default'  => 'telegram_opt_in',
                'configuration' => array('size' => 40, 'length' => 80),
            )),
            'opt_in_default_when_absent' => new BooleanField(array(
                'label'   => 'Default to opt-IN when field is absent',
                'default' => true,
                'configuration' => array(
                    'desc' => 'When the opt-in field is missing from the customer\'s profile, the default behavior. Recommended ON for backwards compatibility with existing customers.',
                ),
            )),
            'link_token_ttl' => new TextboxField(array(
                'label'   => 'Linking token TTL (seconds)',
                'default' => '900',
                'configuration' => array('size' => 10, 'length' => 8),
                'hint'    => 'How long a generated /start linking token remains valid before the customer must re-request it. Default 900 = 15 minutes.',
            )),

            // ─── Webhook ─────────────────────────────────────────────────────
            'sec_webhook' => new SectionBreakField(array(
                'label' => '🌐  Webhook (for inbound bot updates)',
                'hint'  => 'Configure the bot to call your osTicket install when users send /start, /unlink, etc. Required for deep-link linking. After saving, click "Update webhook" in the linked docs / use the /tg-set-webhook command.',
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

            // ─── Events ──────────────────────────────────────────────────────
            'sec_events' => new SectionBreakField(array(
                'label' => '🔔  Per-event notification matrix',
                'hint'  => 'Independent toggle per audience per event. Same model as the Evolution API plugin.',
            )),

            'evt_ticket_created__client' => new BooleanField(array(
                'label' => 'Ticket created → notify customer',
                'default' => true,
            )),
            'evt_ticket_created__admin' => new BooleanField(array(
                'label' => 'Ticket created → notify admins',
                'default' => true,
            )),
            'evt_user_reply__admin' => new BooleanField(array(
                'label' => 'Customer reply → notify admins',
                'default' => true,
            )),
            'evt_staff_reply__client' => new BooleanField(array(
                'label' => 'Staff reply → notify customer',
                'default' => true,
            )),
            'evt_staff_reply__admin' => new BooleanField(array(
                'label' => 'Staff reply → notify admins',
                'default' => false,
            )),
            'evt_status_changed__client' => new BooleanField(array(
                'label' => 'Status changed → notify customer',
                'default' => true,
            )),
            'evt_status_changed__admin' => new BooleanField(array(
                'label' => 'Status changed → notify admins',
                'default' => false,
            )),
            'evt_assignment_changed__admin' => new BooleanField(array(
                'label' => 'Assignment changed → notify admins',
                'default' => false,
            )),

            // ─── Formatting ──────────────────────────────────────────────────
            'sec_format' => new SectionBreakField(array(
                'label' => '✉️  Message formatting',
                'hint'  => 'Telegram supports MarkdownV2 (preferred — more flexible) or HTML. Variable values from osTicket are automatically escaped for the selected parse mode. Templates below use {{var}} and {{var|fallback}} placeholders.',
            )),

            'parse_mode' => new ChoiceField(array(
                'label'   => 'Parse mode',
                'default' => 'MarkdownV2',
                'choices' => array(
                    'MarkdownV2' => 'MarkdownV2 (recommended)',
                    'HTML'       => 'HTML',
                    ''           => 'Plain text (no formatting)',
                ),
            )),
            'disable_web_page_preview' => new BooleanField(array(
                'label'   => 'Disable URL previews',
                'default' => true,
                'configuration' => array(
                    'desc' => 'When on, Telegram won\'t expand link cards for URLs in the message.',
                ),
            )),
            'disable_notification' => new BooleanField(array(
                'label'   => 'Silent send (no sound)',
                'default' => false,
                'configuration' => array(
                    'desc' => 'Messages are delivered with no notification sound. Useful for low-priority events.',
                ),
            )),

            // ─── Templates ───────────────────────────────────────────────────
            'sec_templates' => new SectionBreakField(array(
                'label' => '📝  Message templates',
                'hint'  => 'Placeholders: {{ticket_number}} {{subject}} {{name}} {{email}} {{department}} {{priority}} {{status}} {{assignee}} {{poster_type}} {{message}} {{ticket_link}}. For MarkdownV2 use *bold* _italic_ `code` [text](url). Variable values are auto-escaped — write your template assuming you can use raw markdown for static text.',
            )),

            'tpl_client_created' => new TextareaField(array(
                'label'   => 'To customer — ticket created',
                'default' => "Hi {{name}}, we received your ticket *#{{ticket_number}}*\n_{{subject}}_\n\nAn agent will get back to you shortly.",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_client_staff_reply' => new TextareaField(array(
                'label'   => 'To customer — staff replied',
                'default' => "Hi {{name}}, there's a new reply on ticket *#{{ticket_number}}*:\n\n{{message}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_client_status' => new TextareaField(array(
                'label'   => 'To customer — status changed',
                'default' => "Ticket *#{{ticket_number}}* status changed to *{{status}}*.",
                'configuration' => self::plainTextarea(4, 60),
            )),
            'tpl_admin_created' => new TextareaField(array(
                'label'   => 'To admin — ticket created',
                'default' => "*New ticket #{{ticket_number}}*\n*Subject:* {{subject}}\n*From:* {{name}} ({{email}})\n*Department:* {{department}}\n*Priority:* {{priority}}\n\n{{message}}",
                'configuration' => self::plainTextarea(8, 60),
            )),
            'tpl_admin_user_reply' => new TextareaField(array(
                'label'   => 'To admin — customer replied',
                'default' => "*Reply on ticket #{{ticket_number}}*\n*From customer:* {{name}}\n\n{{message}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_admin_staff_reply' => new TextareaField(array(
                'label'   => 'To admin — staff replied',
                'default' => "*Staff reply on ticket #{{ticket_number}}*\n*By:* {{name}}\n\n{{message}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_admin_status' => new TextareaField(array(
                'label'   => 'To admin — status changed',
                'default' => "Ticket *#{{ticket_number}}* → *{{status}}* (assignee: {{assignee|—}})",
                'configuration' => self::plainTextarea(4, 60),
            )),
            'tpl_admin_assignment' => new TextareaField(array(
                'label'   => 'To admin — assignment changed',
                'default' => "Ticket *#{{ticket_number}}* assigned to *{{assignee}}*.",
                'configuration' => self::plainTextarea(4, 60),
            )),

            // ─── Inline buttons ──────────────────────────────────────────────
            'sec_buttons' => new SectionBreakField(array(
                'label' => '🔘  Inline buttons',
                'hint'  => 'Attach inline keyboard buttons to outgoing messages. Customers and admins see them under the message and can tap to open the ticket in their browser.',
            )),

            'btn_view_ticket' => new BooleanField(array(
                'label'   => 'Show "View ticket" button',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Adds a URL button labeled "View ticket" that opens the ticket page in osTicket.',
                ),
            )),
            'btn_view_ticket_label' => new TextboxField(array(
                'label'   => '"View ticket" button label',
                'default' => '🎟 View ticket',
                'configuration' => array('size' => 40, 'length' => 60),
            )),
            'btn_reply' => new BooleanField(array(
                'label'   => 'Show "Reply" button (admins only)',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Adds a URL button that takes admins straight to the reply form. Hidden for customer notifications.',
                ),
            )),
            'btn_reply_label' => new TextboxField(array(
                'label'   => '"Reply" button label',
                'default' => '💬 Reply',
                'configuration' => array('size' => 40, 'length' => 60),
            )),

            // ─── Misc ────────────────────────────────────────────────────────
            'sec_misc' => new SectionBreakField(array(
                'label' => '🌐  Links & pacing',
            )),

            'base_url' => new TextboxField(array(
                'label'   => 'osTicket base URL',
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'    => 'Required when {{ticket_link}} or inline buttons are used. Example: https://tickets.example.com (no trailing slash).',
            )),
            'send_delay_ms' => new TextboxField(array(
                'label'   => 'Delay between admin sends (ms)',
                'default' => '0',
                'configuration' => array('size' => 8, 'length' => 6),
                'hint'    => 'Pacing between consecutive admin chat sends to avoid Telegram rate limits (30 messages/sec to different chats, 1 msg/sec to the same chat). 0 disables pacing.',
            )),

            // ─── Sentry (optional) ───────────────────────────────────────────
            'sec_sentry' => new SectionBreakField(array(
                'label' => '🛡️  Sentry — error reporting (optional)',
                'hint'  => 'Same lightweight Sentry integration as the Evolution API plugin. Leave DSN blank to disable.',
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
        if (isset($config['admin_chat_ids'])) {
            $raw   = preg_split('/\r?\n/', (string) $config['admin_chat_ids']);
            $clean = array();
            foreach ($raw as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                if (!preg_match('/^-?\d{4,20}$/', $line)) {
                    $errors['admin_chat_ids'] = sprintf('Invalid chat ID "%s" — must be a signed integer.', $line);
                    return false;
                }
                $clean[] = $line;
            }
            $config['admin_chat_ids'] = implode("\n", $clean);
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
