<?php
/**
 * Main plugin class: hooks osTicket signals and dispatches Telegram Bot
 * notifications.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.plugin.php';
require_once 'config.php';
require_once dirname(__FILE__) . '/lib/TelegramBotClient.php';
require_once dirname(__FILE__) . '/lib/TelegramFormatter.php';
require_once dirname(__FILE__) . '/lib/InlineKeyboardBuilder.php';
require_once dirname(__FILE__) . '/lib/UserLinkStore.php';
require_once dirname(__FILE__) . '/lib/SentryReporter.php';
require_once dirname(__FILE__) . '/lib/LogRedactor.php';

class TelegramBotNotificationsPlugin extends Plugin {

    var $config_class = 'TelegramBotNotificationsPluginConfig';

    /** @var TelegramBotClient */
    private $api;
    /** @var TgUserLinkStore */
    private $links;
    /** @var TgSentryReporter */
    private $sentry;
    /** @var array<string,bool> per-(ticket,kind) request dedup */
    private $sentInRequest = array();
    /**
     * Cached config bound at bootstrap time. PluginManager::bootstrap()
     * clears `$plugin->config = null` after each plugin's instances finish
     * bootstrapping, so any later call to `$this->cfg()` (from a
     * signal handler) would otherwise return an empty default-namespaced
     * config. We snapshot the live config here while it's still bound and
     * use it from every handler via `$this->cfg()`.
     *
     * @var PluginConfig|null
     */
    private $cachedCfg;

    /**
     * Defaults for "preference" settings that no longer live in the plugin
     * admin form (moved to /scp/link-telegram.php → Notificaciones, Plantillas,
     * Formato, Vinculación tabs). These return when the corresponding row is
     * missing from ostt4_config so a fresh install still has working
     * notifications before the admin opens the staff page once.
     *
     * Keys mirror exactly what link-telegram.php saves.
     */
    public static function prefDefaults() {
        return array(
            // Recipients
            'notify_clients'                => '1',
            'notify_admins'                 => '1',
            'admin_chat_ids'                => '',
            // Linking — deep-link is the only supported flow. Customers
            // who don't have a linked chat get an opt-in email after their
            // ticket is created (see emailLinkOffer below). Opt-out = /unlink.
            'link_token_ttl'                => '900',
            'send_link_offer_email'         => '1',
            // Event matrix
            'evt_ticket_created__client'    => '1',
            'evt_ticket_created__admin'     => '1',
            'evt_user_reply__admin'         => '1',
            'evt_staff_reply__client'       => '1',
            'evt_staff_reply__admin'        => '0',
            'evt_status_changed__client'    => '1',
            'evt_status_changed__admin'     => '0',
            'evt_assignment_changed__admin' => '0',
            // Formatting + buttons
            'parse_mode'                    => 'HTML',
            'disable_web_page_preview'      => '1',
            'disable_notification'          => '0',
            'btn_view_ticket'               => '1',
            'btn_view_ticket_label'         => '🎟 View ticket',
            'btn_reply'                     => '1',
            'btn_reply_label'               => '💬 Reply',
            'send_delay_ms'                 => '0',
            'base_url'                      => '',
            // Templates
            'tpl_client_created'     => "Hello <b>{{name}}</b>, we received your ticket <b>#{{ticket_number}}</b>\n<i>{{subject}}</i>\n\nAn agent will get back to you shortly.",
            'tpl_client_staff_reply' => "Hello <b>{{name}}</b>, there's a new reply on ticket <b>#{{ticket_number}}</b>:\n\n{{message}}",
            'tpl_client_status'      => "Ticket <b>#{{ticket_number}}</b> status changed to <b>{{status}}</b>.",
            'tpl_admin_created'      => "<b>New ticket #{{ticket_number}}</b>\n<b>Subject:</b> {{subject}}\n<b>From:</b> {{name}} ({{email}})\n<b>Department:</b> {{department}}\n<b>Priority:</b> {{priority}}\n\n{{message}}",
            'tpl_admin_user_reply'   => "<b>Reply on ticket #{{ticket_number}}</b>\n<b>From customer:</b> {{name}}\n\n{{message}}",
            'tpl_admin_staff_reply'  => "<b>Staff reply on ticket #{{ticket_number}}</b>\n<b>By:</b> {{name}}\n\n{{message}}",
            'tpl_admin_status'       => "Ticket <b>#{{ticket_number}}</b> → <b>{{status}}</b> (assignee: {{assignee|—}})",
            'tpl_admin_assignment'   => "Ticket <b>#{{ticket_number}}</b> assigned to <b>{{assignee}}</b>.",
        );
    }

    /**
     * Read a preference setting with a fallback to prefDefaults() when the
     * underlying config row is missing. Use this for everything that lives
     * in link-telegram.php (notif/templates/format/linking tabs). The plugin
     * admin form fields (bot creds, webhook, Sentry, debug) still use
     * `$this->cfg()->get(...)` directly — they're guaranteed to be set
     * because PluginConfig seeds their defaults on first save.
     */
    private function pref($key) {
        $cfg = $this->cfg();
        if ($cfg) {
            $v = $cfg->get($key);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        $d = self::prefDefaults();
        return array_key_exists($key, $d) ? $d[$key] : null;
    }

    /**
     * Return the instance-bound PluginConfig. Side-loads the first active
     * instance on first call if needed, then caches. Safe to call from any
     * point in the request lifecycle.
     */
    private function cfg() {
        if ($this->cachedCfg) {
            return $this->cachedCfg;
        }
        // Try the parent's live config first (set if we're inside our own bootstrap).
        // Call parent::getConfig() (NOT $this->getConfig()) to avoid any
        // chance of recursion if subclasses override getConfig().
        $live = parent::getConfig();
        if ($live && $live->get('bot_token')) {
            $this->cachedCfg = $live;
            return $live;
        }
        // Fall back: side-load the active instance config explicitly.
        // PluginManager::bootstrap() clears $plugin->config = null after
        // running each plugin's instance bootstraps, so by signal-handler
        // time we have to re-fetch via the active instance.
        if (method_exists($this, 'getActiveInstances')) {
            foreach ($this->getActiveInstances() as $inst) {
                $this->cachedCfg = parent::getConfig($inst);
                return $this->cachedCfg;
            }
        }
        $this->cachedCfg = $live;
        return $this->cachedCfg;
    }

    // ─── Lifecycle ───────────────────────────────────────────────────────

    function bootstrap() {
        $cfg = $this->cfg();

        $this->sentry = new TgSentryReporter($cfg->get('sentry_dsn'));
        $this->sentry->setEnvironment($cfg->get('sentry_environment') ?: 'production');
        $this->sentry->addTag('plugin', 'telegram-bot-notifications');

        if ($cfg->get('sentry_capture_global') && $this->sentry->isEnabled()) {
            $this->installGlobalSentryHandlers();
        }

        if ($this->anyOn('evt_ticket_created__client', 'evt_ticket_created__admin')) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        }
        if ($this->anyOn('evt_user_reply__admin', 'evt_staff_reply__client', 'evt_staff_reply__admin')) {
            Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
        }
        if ($this->anyOn('evt_status_changed__client', 'evt_status_changed__admin', 'evt_assignment_changed__admin')) {
            Signal::connect('model.updated', array($this, 'onModelUpdated'));
        }

        // Register a "Telegram" entry in the staff "Applications" dropdown.
        // This is osTicket's plugin-native extension point for adding staff
        // nav items (Application::registerStaffApp → nav.php picks it up).
        // Survives osTicket upgrades with zero core-file edits.
        $this->registerStaffNav();
    }

    /**
     * Add a "Telegram" link to the staff Applications dropdown so staff
     * have a discoverable entry point. Points at scp/link-telegram.php
     * (the staff-facing manage page).
     *
     * If link-telegram.php isn't deployed to scp/, the menu item still
     * appears but clicks 404 — admins should deploy it as part of plugin
     * install (see docs/user-linking.md).
     */
    private function registerStaffNav() {
        // osTicket only requires class.app.php from class.nav.php at render
        // time, which is too late for us. Load it now so Application is
        // available — it's safe to require_once even if it's loaded later.
        if (!class_exists('Application')
            && defined('INCLUDE_DIR')
            && is_file(INCLUDE_DIR . 'class.app.php')) {
            require_once INCLUDE_DIR . 'class.app.php';
        }
        if (!class_exists('Application')) {
            return;
        }
        try {
            // registerStaffApp is declared without `static` in osTicket but
            // mutates a static property. Under PHP 8.x calling it statically
            // throws — instantiate Application and call on the instance.
            $app = new Application();
            $app->registerStaffApp(
                'Telegram',
                'link-telegram.php',
                array(
                    'title'     => 'Notificaciones por Telegram',
                    'iconclass' => 'comment',
                )
            );
        } catch (Exception $e) {
            // Non-critical — silent.
        }
    }

    // ─── Public API for webhook handler ──────────────────────────────────

    /**
     * Process an inbound Telegram Update (parsed JSON). Used by webhook.php.
     * Handles /start, /unlink, /status, and ignores everything else.
     */
    public function processUpdate(array $update) {
        try {
            $msg = isset($update['message']) ? $update['message'] : null;
            if (!$msg) {
                // Could be a callback_query etc. Out of scope for v0.1.
                return;
            }
            $chatId = isset($msg['chat']['id']) ? (int) $msg['chat']['id'] : 0;
            $text   = isset($msg['text']) ? (string) $msg['text'] : '';
            if ($chatId === 0 || $text === '') {
                return;
            }

            $cmd = $this->parseCommand($text);
            if ($cmd === null) {
                return;
            }
            switch ($cmd['name']) {
                case 'start':
                    $this->handleStart($chatId, $cmd['arg']);
                    break;
                case 'unlink':
                    $this->handleUnlink($chatId);
                    break;
                case 'status':
                    $this->handleStatus($chatId);
                    break;
                case 'whoami':
                case 'id':
                case 'chatid':
                    $this->handleWhoami($chatId);
                    break;
                case 'help':
                    $this->handleHelp($chatId);
                    break;
                default:
                    // Ignore unknown commands silently.
                    break;
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'webhook.update'));
        }
    }

    /**
     * Validate the bot token by calling getMe(). Returns the bot's username
     * on success or null on failure.
     */
    public function checkBot() {
        $res = $this->api()->getMe();
        if (!$res['ok'] || !isset($res['body']['result']['username'])) {
            return null;
        }
        return (string) $res['body']['result']['username'];
    }

    /**
     * Configure (or update) the bot's webhook. Returns the result envelope.
     */
    public function applyWebhookFromConfig() {
        $cfg = $this->cfg();
        $url = trim((string) $cfg->get('webhook_public_url'));
        if ($url === '') {
            return array('ok' => false, 'error' => 'webhook_public_url not configured');
        }
        $opts = array(
            'allowed_updates'      => array('message'),
            'drop_pending_updates' => true,
        );
        $secret = trim((string) $cfg->get('webhook_secret_token'));
        if ($secret !== '') {
            $opts['secret_token'] = $secret;
        }
        return $this->api()->setWebhook($url, $opts);
    }

    /**
     * Generate a /start linking token + return the t.me deep-link URL.
     * For customer (end-user) linking. See generateStaffLinkUrl for the
     * admin/staff variant.
     */
    public function generateLinkUrl($userId) {
        $cfg = $this->cfg();
        $bot = trim((string) $cfg->get('bot_username'));
        if ($bot === '') {
            return null;
        }
        $token = $this->links()->issueToken((int) $userId);
        return 'https://t.me/' . rawurlencode($bot) . '?start=' . rawurlencode($token);
    }

    /**
     * Same as generateLinkUrl, but binds the token to a Staff record so
     * that after /start the staff member's chat_id gets added to the
     * admin notification recipient list (see sendToAdmins).
     */
    public function generateStaffLinkUrl($staffId) {
        $cfg = $this->cfg();
        $bot = trim((string) $cfg->get('bot_username'));
        if ($bot === '') {
            return null;
        }
        $token = $this->links()->issueStaffToken((int) $staffId);
        return 'https://t.me/' . rawurlencode($bot) . '?start=' . rawurlencode($token);
    }

    // ─── Signal handlers ─────────────────────────────────────────────────

    function onTicketCreated($ticket) {
        try {
            $vars = $this->ticketVars($ticket);
            $vars['message'] = $this->firstMessage($ticket);

            if ($this->clientShouldFire('evt_ticket_created')) {
                $this->sendToClient($ticket, $this->pref('tpl_client_created'), $vars, /*adminKb*/ false);
            }
            if ($this->adminShouldFire('evt_ticket_created')) {
                $this->sendToAdmins($this->pref('tpl_admin_created'), $vars, $this->buildKeyboard($ticket, true));
            }
            // Customer has no linked Telegram yet → email them an invitation
            // with a one-shot deep-link token. Works for both logged-in
            // customers and walk-in visitors: osTicket always has a User
            // record by ticket creation time, and we email to the User's
            // address (no session required).
            if ($this->pref('notify_clients')
                    && $this->pref('send_link_offer_email')
                    && $this->resolveClientChatId($ticket) === null) {
                $this->emailLinkOffer($ticket);
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'ticket.created'));
        }
    }

    /**
     * Email the ticket owner a Telegram deep-link so they can opt-in to
     * notifications. Skips silently if we can't determine an email address
     * or if the bot isn't configured.
     */
    private function emailLinkOffer(Ticket $ticket) {
        $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
        if (!$owner) { return; }
        $userId = method_exists($owner, 'getId') ? (int) $owner->getId() : 0;
        if (!$userId) { return; }

        $email = '';
        try { $email = (string) $ticket->getEmail(); } catch (Exception $e) {}
        if ($email === '' && method_exists($owner, 'getEmail')) {
            try { $email = (string) $owner->getEmail(); } catch (Exception $e) {}
        }
        if (!$email || !preg_match('/^[^\s@]+@[^\s@]+$/', $email)) { return; }

        // Skip automated/non-deliverable senders. Mailing them an invite is
        // guaranteed to bounce, which loops back into the support inbox as a
        // brand-new "Mail delivery failed" ticket. Anything from a bounces
        // mailbox, postmaster, mailer-daemon, or a do-not-reply alias is a
        // robot that can't read the invite anyway.
        $emailLower = strtolower($email);
        if (preg_match(
                '/^(mailer-daemon|postmaster|no-?reply|noreply|do-?not-?reply|donotreply|bounces?|automated|system|abuse|root|daemon)@/',
                $emailLower
            )
            || strpos($emailLower, '@bounces.') !== false
            || strpos($emailLower, 'mailer-daemon@') !== false
            || strpos($emailLower, '.bounces.') !== false
        ) {
            return;
        }

        $url = $this->generateLinkUrl($userId);
        if (!$url) { return; }

        $name = '';
        try { $name = (string) $ticket->getName(); } catch (Exception $e) {}
        if ($name === '' && method_exists($owner, 'getName')) {
            try { $name = (string) $owner->getName(); } catch (Exception $e) {}
        }
        $hello   = $name !== '' ? Format::htmlchars($name) : 'Hola';
        $number  = Format::htmlchars((string) $ticket->getNumber());
        $urlSafe = Format::htmlchars($url);

        $subject = 'Vincula tu Telegram para recibir actualizaciones de tu ticket #' . $number;
        $body = '<p>' . $hello . ',</p>'
              . '<p>Hemos recibido tu ticket <b>#' . $number . '</b>. Si quieres recibir actualizaciones '
              . 'directamente en Telegram (más rápido que el correo), vincula tu cuenta con un clic:</p>'
              . '<p style="margin:18px 0;"><a href="' . $urlSafe . '" '
              . 'style="display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;'
              . 'text-decoration:none;border-radius:6px;font-weight:600;">Vincular mi Telegram</a></p>'
              . '<p style="font-size:0.9em;color:#666;">El enlace se abre en la app de Telegram y solo es válido '
              . 'una vez. Si prefieres seguir recibiendo todo por email, ignora este mensaje. '
              . 'Para desvincular más adelante, envía <code>/unlink</code> al bot.</p>';

        try {
            // osTicket 1.18+ namespaces Mailer under osTicket\Mail. Use the
            // FQCN to avoid autoload trouble at signal-handler time.
            \osTicket\Mail\Mailer::sendmail($email, $subject, $body, null, array(
                'from_name' => 'Soporte',
            ));
            $this->log('info', 'Sent Telegram link-offer email', array(
                'ticket' => $number,
                'email'  => $email,
            ));
        } catch (Exception $e) {
            $this->report($e, array('event' => 'link-offer-email'));
        }
    }

    function onThreadEntryCreated($entry) {
        try {
            $thread = method_exists($entry, 'getThread') ? $entry->getThread() : null;
            if (!$thread) { return; }
            $ticket = $thread->getObject();
            if (!$ticket || !($ticket instanceof Ticket)) { return; }

            $posterType = $this->posterType($entry);
            $isStaff = $posterType === 'staff';
            $isUser  = $posterType === 'user' || $posterType === 'collaborator';

            $vars = $this->ticketVars($ticket);
            $vars['poster_type'] = ucfirst($posterType);
            $vars['name']        = $this->posterName($entry, $vars['name']);
            $body = TgFormatter::truncate($this->bodyToText($entry->getBody(), $this->pref('parse_mode')), 2500);
            $vars['message'] = $body;

            if ($isStaff) {
                if ($this->clientShouldFire('evt_staff_reply')) {
                    $this->sendToClient($ticket, $this->pref('tpl_client_staff_reply'), $vars, false);
                }
                if ($this->adminShouldFire('evt_staff_reply')) {
                    $tpl = $this->pref('tpl_admin_staff_reply');
                    if ($tpl === null || $tpl === '') {
                        $tpl = $this->pref('tpl_admin_user_reply');
                    }
                    $this->sendToAdmins($tpl, $vars, $this->buildKeyboard($ticket, true));
                }
            }
            if ($isUser) {
                if ($this->adminShouldFire('evt_user_reply')) {
                    $this->sendToAdmins($this->pref('tpl_admin_user_reply'), $vars, $this->buildKeyboard($ticket, true));
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'threadentry.created'));
        }
    }

    function onModelUpdated($model) {
        if (!($model instanceof Ticket)) { return; }
        $tid = $model->getId();
        try {
            $dirty = method_exists($model, 'dirty') ? $model->dirty : array();
            if (!is_array($dirty)) { $dirty = array(); }

            $statusChanged   = isset($dirty['status_id'])
                && !$this->markOnce($tid, 'status');
            $assigneeChanged = (isset($dirty['staff_id']) || isset($dirty['team_id']))
                && !$this->markOnce($tid, 'assignment');

            if (!$statusChanged && !$assigneeChanged) { return; }

            $vars = $this->ticketVars($model);

            if ($statusChanged) {
                if ($this->clientShouldFire('evt_status_changed')) {
                    $this->sendToClient($model, $this->pref('tpl_client_status'), $vars, false);
                }
                if ($this->adminShouldFire('evt_status_changed')) {
                    $this->sendToAdmins($this->pref('tpl_admin_status'), $vars, $this->buildKeyboard($model, true));
                }
            }
            if ($assigneeChanged) {
                if ($this->adminShouldFire('evt_assignment_changed')) {
                    $this->sendToAdmins($this->pref('tpl_admin_assignment'), $vars, $this->buildKeyboard($model, true));
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'model.updated'));
        }
    }

    // ─── Senders ─────────────────────────────────────────────────────────

    private function sendToClient(Ticket $ticket, $template, array $vars, $forAdmin = false) {
        // No opt-in field check: linking IS opt-in. Customer can /unlink
        // anytime to stop notifications.
        $chatId = $this->resolveClientChatId($ticket);
        if (!$chatId) {
            $this->log('debug', 'No linked Telegram chat for ticket #' . $vars['ticket_number']);
            return;
        }

        $text = TgFormatter::render($template, $this->escapeVarsForParseMode($vars));
        $text = TgFormatter::truncate($text, 3500);
        $kb = $this->buildKeyboard($ticket, $forAdmin);
        $this->dispatchSend($chatId, $text, $kb);
    }

    private function sendToAdmins($template, array $vars, $keyboardMarkup = null) {
        // Source 1: manual list from preferences (one per line).
        $raw = (string) $this->pref('admin_chat_ids');
        $list = array();
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^-?\d{4,20}$/', $line)) {
                $list[] = $line;
            }
        }
        // Source 2: chat_ids linked to Staff records via /start. Lets a staff
        // member join the admin recipient list with a single click instead of
        // an admin editing the config.
        try {
            foreach ($this->links()->allStaffChatIds() as $cid) {
                $list[] = (string) $cid;
            }
        } catch (Exception $e) {
            $this->log('warning', 'allStaffChatIds failed', array('exception' => $e->getMessage()));
        }
        // Dedupe while preserving order.
        $list = array_values(array_unique($list));

        if (!$list) {
            $this->log('debug', 'notify_admins is on but no admin chat IDs configured or linked');
            return;
        }

        $text = TgFormatter::render($template, $this->escapeVarsForParseMode($vars));
        $text = TgFormatter::truncate($text, 3500);

        $delayMs = max(0, (int) $this->pref('send_delay_ms'));
        foreach ($list as $i => $chatId) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $this->dispatchSend($chatId, $text, $keyboardMarkup);
        }
    }

    private function dispatchSend($chatId, $text, $keyboardMarkup = null) {
        $opts = array();
        $pm = $this->pref('parse_mode');
        if ($pm) { $opts['parse_mode'] = $pm; }
        if ($this->pref('disable_web_page_preview')) { $opts['disable_web_page_preview'] = true; }
        if ($this->pref('disable_notification'))     { $opts['disable_notification'] = true; }
        if (is_array($keyboardMarkup))               { $opts['reply_markup'] = $keyboardMarkup; }

        $res = $this->api()->sendMessage($chatId, $text, $opts);
        if (!$res['ok']) {
            $this->log('error', 'sendMessage failed', array(
                'chat_id' => $chatId,
                'status' => $res['status'],
                'error'  => $res['error'],
            ));
            $this->sentry->captureMessage(
                'Telegram sendMessage failed: ' . $res['error'],
                'error',
                array('tags' => array('endpoint' => 'sendMessage', 'status' => (string) $res['status']))
            );
        } else {
            $this->log('info', 'sendMessage ok', array('chat_id' => $chatId, 'status' => $res['status']));
        }
    }

    // ─── Webhook command handlers ────────────────────────────────────────

    private function handleStart($chatId, $arg) {
        // /start without an arg → bot greeting.
        if (!$arg) {
            $msg  = "¡Hola! Soy el bot de notificaciones de soporte.\n\n";
            $msg .= "Para vincular este chat con tu cuenta, abre el botón \"Vincular Telegram\" en tu correo de creación de ticket o en tu perfil.\n\n";
            $msg .= "Tu chat_id es: " . $chatId . "\n\n";
            $msg .= "Envía /help para ver todos los comandos.";
            $this->reply($chatId, $msg);
            return;
        }

        // Try user token first, then staff token. Tokens live in separate
        // tables — a given token will resolve to exactly one of the two.
        $userId = $this->links()->consumeToken($arg);
        if ($userId !== null) {
            $this->links()->link($userId, $chatId);
            $this->log('info', 'Linked user', array('user_id' => $userId, 'chat_id' => $chatId));
            $this->reply($chatId, '✅ Vinculado. Aquí recibirás las actualizaciones de tus tickets. Envía /unlink para desvincular.');
            return;
        }

        $staffId = $this->links()->consumeStaffToken($arg);
        if ($staffId !== null) {
            $this->links()->linkStaff($staffId, $chatId);
            $this->log('info', 'Linked staff', array('staff_id' => $staffId, 'chat_id' => $chatId));
            $this->reply($chatId, '✅ Vinculado como staff. Aquí recibirás las notificaciones admin. Envía /unlink para desvincular.');
            return;
        }

        $this->reply($chatId, '❌ El token es inválido o expiró. Solicita uno nuevo desde tu perfil o desde el correo de tu ticket.');
    }

    private function handleUnlink($chatId) {
        $userId  = $this->links()->userIdForChat($chatId);
        $staffId = $this->links()->staffIdForChat($chatId);

        if ($userId === null && $staffId === null) {
            $this->reply($chatId, 'Este chat no está vinculado a ninguna cuenta.');
            return;
        }
        if ($userId !== null) {
            $this->links()->unlinkByChat($chatId);
            $this->log('info', 'Unlinked user', array('user_id' => $userId, 'chat_id' => $chatId));
        }
        if ($staffId !== null) {
            $this->links()->unlinkStaffByChat($chatId);
            $this->log('info', 'Unlinked staff', array('staff_id' => $staffId, 'chat_id' => $chatId));
        }
        $this->reply($chatId, '🔌 Desvinculado. Ya no recibirás notificaciones aquí.');
    }

    private function handleStatus($chatId) {
        $userId  = $this->links()->userIdForChat($chatId);
        $staffId = $this->links()->staffIdForChat($chatId);
        $parts = array();
        if ($userId !== null)  { $parts[] = 'cliente #'  . $userId; }
        if ($staffId !== null) { $parts[] = 'staff #' . $staffId; }
        if (!$parts) {
            $this->reply($chatId, 'Este chat no está vinculado. Tu chat_id es: ' . $chatId);
            return;
        }
        $this->reply($chatId, 'Vinculado como: ' . implode(' + ', $parts) . '.');
    }

    /**
     * /whoami /id /chatid → reply with the user's chat_id so they can
     * paste it elsewhere or share it with support. Doesn't require the
     * chat to be linked — anyone who messages the bot can use it.
     */
    private function handleWhoami($chatId) {
        $this->reply($chatId, 'Tu chat_id es: ' . $chatId);
    }

    /**
     * /help → reply with the list of supported commands. Discoverable
     * UX so users don't have to guess.
     */
    private function handleHelp($chatId) {
        $msg  = "Comandos disponibles:\n";
        $msg .= "/start  — saludo del bot\n";
        $msg .= "/start <token>  — vincular cuenta (token desde el panel)\n";
        $msg .= "/status — ver si este chat está vinculado\n";
        $msg .= "/whoami — mostrar tu chat_id\n";
        $msg .= "/unlink — desvincular este chat\n";
        $msg .= "/help   — esta ayuda";
        $this->reply($chatId, $msg);
    }

    private function reply($chatId, $text) {
        $this->api()->sendMessage($chatId, $text);
    }

    private function parseCommand($text) {
        if (strlen($text) === 0 || $text[0] !== '/') {
            return null;
        }
        // Strip optional @bot suffix: `/start@MyBot arg`.
        $rest = ltrim(substr($text, 1));
        $space = strpos($rest, ' ');
        $cmdWithBot = $space === false ? $rest : substr($rest, 0, $space);
        $at = strpos($cmdWithBot, '@');
        $name = strtolower($at === false ? $cmdWithBot : substr($cmdWithBot, 0, $at));
        $arg = $space === false ? null : trim(substr($rest, $space + 1));
        return array('name' => $name, 'arg' => $arg);
    }

    // ─── Lazy deps ───────────────────────────────────────────────────────

    private function api() {
        if ($this->api !== null) { return $this->api; }
        $cfg = $this->cfg();
        $client = new TelegramBotClient(
            $cfg->get('bot_token'),
            $cfg->get('bot_api_base_url') ?: null
        );
        $client->setVerifySsl((bool) $cfg->get('verify_ssl'));
        $client->setTimeout(max(3, (int) $cfg->get('http_timeout')));
        $client->setMaxAttempts(max(1, (int) ($cfg->get('http_max_attempts') ?: 3)));
        $self = $this;
        $client->setLogger(function ($lvl, $msg, $ctx) use ($self) {
            $self->log($lvl, '[tg] ' . $msg, $ctx);
        });
        $this->api = $client;
        return $this->api;
    }

    private function links() {
        if ($this->links === null) {
            $ttl = max(60, (int) ($this->pref('link_token_ttl') ?: 900));
            $this->links = new TgUserLinkStore($ttl);
        }
        return $this->links;
    }

    // ─── Decision helpers ────────────────────────────────────────────────

    private function anyOn(/* ...keys */) {
        foreach (func_get_args() as $k) {
            if ($this->pref($k)) { return true; }
        }
        return false;
    }

    private function clientShouldFire($eventKey) {
        return $this->pref('notify_clients') && $this->pref($eventKey . '__client');
    }

    private function adminShouldFire($eventKey) {
        return $this->pref('notify_admins') && $this->pref($eventKey . '__admin');
    }

    private function markOnce($ticketId, $kind) {
        $key = $ticketId . ':' . $kind;
        if (isset($this->sentInRequest[$key])) { return true; }
        $this->sentInRequest[$key] = true;
        return false;
    }

    // ─── Resolve client chat_id ──────────────────────────────────────────

    /**
     * Resolve the customer's Telegram chat_id from the deep-link store.
     * Returns null when the customer hasn't linked yet — callers should
     * trigger emailLinkOffer() so the customer gets the invitation.
     */
    private function resolveClientChatId(Ticket $ticket) {
        try {
            $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
            if (!$owner) { return null; }
            $userId = method_exists($owner, 'getId') ? (int) $owner->getId() : 0;
            if (!$userId) { return null; }
            return $this->links()->chatIdForUser($userId);
        } catch (Exception $e) {
            return null;
        }
    }

    // ─── Template vars + escaping ────────────────────────────────────────

    private function ticketVars(Ticket $ticket) {
        $deptName = '—';
        try { $d = $ticket->getDept(); if ($d) { $deptName = $d->getName(); } } catch (Exception $e) {}
        $priName = 'Normal';
        try { $p = $ticket->getPriority(); if ($p) { $priName = $p->getDesc(); } } catch (Exception $e) {}
        $statusName = '—';
        try {
            if (method_exists($ticket, 'getStatus')) {
                $s = $ticket->getStatus();
                $statusName = is_object($s) && method_exists($s, 'getName')
                    ? $s->getName()
                    : ($s ? (string) $s : '—');
            }
        } catch (Exception $e) {}
        $assignee = '';
        try {
            if (method_exists($ticket, 'getAssignee')) {
                $a = $ticket->getAssignee();
                if (is_object($a) && method_exists($a, 'getName')) {
                    $assignee = (string) $a->getName();
                } elseif (is_string($a)) {
                    $assignee = $a;
                }
            }
        } catch (Exception $e) {}
        $email = '';
        try { if (method_exists($ticket, 'getEmail')) { $email = (string) $ticket->getEmail(); } } catch (Exception $e) {}
        $name = '';
        try { if (method_exists($ticket, 'getName')) { $name = (string) $ticket->getName(); } } catch (Exception $e) {}

        return array(
            'ticket_number' => (string) $ticket->getNumber(),
            'subject'       => method_exists($ticket, 'getSubject') ? (string) $ticket->getSubject() : '',
            'name'          => $name ?: '—',
            'email'         => $email,
            'department'    => $deptName,
            'priority'      => $priName,
            'status'        => $statusName,
            'assignee'      => $assignee,
            'ticket_link'   => $this->ticketLink($ticket),
        );
    }

    /**
     * Escape every variable value for the configured parse mode so admins
     * can write *bold* in their template safely (static) while user input
     * gets auto-escaped.
     */
    private function escapeVarsForParseMode(array $vars) {
        $mode = $this->pref('parse_mode');
        if ($mode === 'MarkdownV2') {
            foreach ($vars as $k => $v) {
                if ($k === 'ticket_link') {
                    // URL goes through the link grammar, escape only `)` and `\`.
                    $vars[$k] = str_replace(array('\\', ')'), array('\\\\', '\\)'), (string) $v);
                } elseif ($k === 'message') {
                    // The message body may have come pre-formatted from bodyToText.
                    $vars[$k] = (string) $v;
                } else {
                    $vars[$k] = TgFormatter::escapeMarkdownV2((string) $v);
                }
            }
        } elseif ($mode === 'HTML') {
            foreach ($vars as $k => $v) {
                if ($k === 'message' || $k === 'ticket_link') {
                    $vars[$k] = (string) $v;
                } else {
                    $vars[$k] = TgFormatter::escapeHtml((string) $v);
                }
            }
        }
        return $vars;
    }

    private function bodyToText($html, $parseMode) {
        if ($parseMode === 'MarkdownV2') {
            return TgFormatter::htmlToMarkdownV2($html);
        }
        if ($parseMode === 'HTML') {
            return TgFormatter::htmlToTelegram($html);
        }
        // Plain.
        $stripped = strip_tags((string) $html);
        return trim(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function ticketLink(Ticket $ticket) {
        $base = trim((string) $this->pref('base_url'));
        if ($base === '') { return ''; }
        return rtrim($base, '/') . '/scp/tickets.php?id=' . (int) $ticket->getId();
    }

    private function firstMessage(Ticket $ticket) {
        try {
            $thread = $ticket->getThread();
            if (!$thread) { return ''; }
            $entries = $thread->getEntries();
            if (!$entries) { return ''; }
            $first = $entries[0];
            $mode = $this->pref('parse_mode');
            return TgFormatter::truncate($this->bodyToText($first->getBody(), $mode), 2500);
        } catch (Exception $e) {
            return '';
        }
    }

    private function posterType($entry) {
        try {
            $p = $entry->getPoster();
            if ($p instanceof Staff)        { return 'staff'; }
            if ($p instanceof User)         { return 'user'; }
            if ($p instanceof Collaborator) { return 'collaborator'; }
        } catch (Exception $e) {}
        return 'system';
    }

    private function posterName($entry, $fallback) {
        try {
            $p = $entry->getPoster();
            if (is_object($p) && method_exists($p, 'getName')) {
                $n = (string) $p->getName();
                if ($n !== '') { return $n; }
            }
            if (method_exists($entry, 'getName')) {
                $n = (string) $entry->getName();
                if ($n !== '') { return $n; }
            }
        } catch (Exception $e) {}
        return $fallback ?: '—';
    }

    // ─── Inline keyboards ────────────────────────────────────────────────

    /**
     * Build the inline keyboard appropriate for the audience.
     * For customers: just "View ticket" (the public client URL would be
     * different — use the same scp URL as a known-safe target, the customer
     * gets redirected to login).
     * For admins: "View ticket" + optional "Reply".
     */
    private function buildKeyboard(Ticket $ticket, $forAdmin) {
        $base = trim((string) $this->pref('base_url'));
        if ($base === '') {
            return null;
        }
        $base = rtrim($base, '/');
        $tid  = (int) $ticket->getId();
        $kb   = new TgInlineKeyboard();
        $kb->addRow();

        if ($this->pref('btn_view_ticket')) {
            $label = trim((string) $this->pref('btn_view_ticket_label')) ?: 'View ticket';
            $kb->urlButton($label, $base . '/scp/tickets.php?id=' . $tid);
        }
        if ($forAdmin && $this->pref('btn_reply')) {
            $label = trim((string) $this->pref('btn_reply_label')) ?: 'Reply';
            $kb->urlButton($label, $base . '/scp/tickets.php?id=' . $tid . '#reply');
        }
        return $kb->build();
    }

    // ─── Sentry + logging ────────────────────────────────────────────────

    private function installGlobalSentryHandlers() {
        $sentry = $this->sentry;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($sentry) {
            if (!(error_reporting() & $errno)) { return false; }
            $sentry->captureMessage(
                sprintf('PHP %d: %s at %s:%d', $errno, $errstr, $errfile, $errline),
                $errno === E_NOTICE || $errno === E_USER_NOTICE ? 'info' : 'error'
            );
            return false;
        });
        set_exception_handler(function ($e) use ($sentry) {
            $sentry->captureException($e);
            throw $e;
        });
        register_shutdown_function(function () use ($sentry) {
            $err = error_get_last();
            if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                $sentry->captureMessage(
                    sprintf('FATAL: %s at %s:%d', $err['message'], $err['file'], $err['line']),
                    'fatal'
                );
            }
        });
    }

    public function report($throwable, array $ctx = array()) {
        $this->log('error', $throwable->getMessage(), $ctx + array('class' => get_class($throwable)));
        if ($this->sentry) {
            $this->sentry->captureException($throwable, array('tags' => $ctx));
        }
    }

    public function log($level, $msg, $ctx = array()) {
        $cfg = $this->cfg();
        $debug = (bool) $cfg->get('debug_mode');
        if (!$debug && !in_array($level, array('error', 'warning'), true)) {
            return;
        }
        $line = '[TelegramBotNotifications][' . strtoupper($level) . '] ' . $msg;
        if (!empty($ctx)) {
            $line .= ' ' . json_encode(TgLogRedactor::context($ctx));
        }
        error_log($line);
    }
}
