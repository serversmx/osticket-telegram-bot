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
require_once dirname(__FILE__) . '/lib/SentMessageStore.php';
require_once dirname(__FILE__) . '/lib/RateLimiter.php';
require_once dirname(__FILE__) . '/lib/SafeSentry.php';

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
            'btn_assign_me'                 => '1',
            'btn_assign_me_label'           => '👤 Asignar a mí',
            'btn_close_ticket'              => '1',
            'btn_close_ticket_label'        => '✅ Cerrar ticket',
            'send_delay_ms'                 => '0',
            'base_url'                      => '',
            // Ticket-state sync (v0.3 — see SentMessageStore + RateLimiter)
            'sync_ticket_state'             => '1',   // master toggle
            'sync_on_delete'                => '0',   // GDPR-safe default: only strip buttons on delete, don't re-broadcast subject
            'rate_limit_enabled'            => '1',   // token bucket for editMessage fan-out
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

        // sync_ticket_state feature: edit the ORIGINAL Telegram card when
        // the ticket's lifecycle changes (closed / deleted / transferred /
        // assigned). Design v2 (2026-08-08): defers the actual edit fan-out
        // to cron() so the signal handler never blocks the staff HTTP
        // request; only the row-marking is inline. See syncTicketState().
        if ($this->pref('sync_ticket_state')) {
            Signal::connect('object.created', array($this, 'onObjectCreated'));
            Signal::connect('object.edited', array($this, 'onObjectEdited'));
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
            // Callback queries from inline keyboard buttons (e.g. "Asignar a
            // mí", "Cerrar ticket"). Handled before regular messages because
            // they don't carry a top-level 'message' field on every variant.
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
                return;
            }

            $msg = isset($update['message']) ? $update['message'] : null;
            if (!$msg) {
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
        } catch (\Throwable $e) {
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
            'allowed_updates'      => array('message', 'callback_query'),
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

            $ctxClient = array(
                'ticket_id' => (int) $ticket->getId(),
                'notif_type' => 'ticket.created',
                'subject' => isset($vars['subject']) ? (string) $vars['subject'] : '',
            );
            $ctxAdmin = $ctxClient;
            $ctxAdmin['notif_type'] = 'ticket.created.admin';

            if ($this->clientShouldFire('evt_ticket_created')) {
                $this->sendToClient($ticket, $this->pref('tpl_client_created'), $vars, /*adminKb*/ false, $ctxClient);
            }
            if ($this->adminShouldFire('evt_ticket_created')) {
                $this->sendToAdmins($this->pref('tpl_admin_created'), $vars, $this->buildKeyboard($ticket, true), $ctxAdmin);
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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

            $ctxBase = array(
                'ticket_id' => (int) $ticket->getId(),
                'subject' => isset($vars['subject']) ? (string) $vars['subject'] : '',
            );

            if ($isStaff) {
                if ($this->clientShouldFire('evt_staff_reply')) {
                    $ctx = $ctxBase; $ctx['notif_type'] = 'staff_reply.client';
                    $this->sendToClient($ticket, $this->pref('tpl_client_staff_reply'), $vars, false, $ctx);
                }
                if ($this->adminShouldFire('evt_staff_reply')) {
                    $tpl = $this->pref('tpl_admin_staff_reply');
                    if ($tpl === null || $tpl === '') {
                        $tpl = $this->pref('tpl_admin_user_reply');
                    }
                    $ctx = $ctxBase; $ctx['notif_type'] = 'staff_reply.admin';
                    $this->sendToAdmins($tpl, $vars, $this->buildKeyboard($ticket, true), $ctx);
                }
            }
            if ($isUser) {
                if ($this->adminShouldFire('evt_user_reply')) {
                    $ctx = $ctxBase; $ctx['notif_type'] = 'user_reply.admin';
                    $this->sendToAdmins($this->pref('tpl_admin_user_reply'), $vars, $this->buildKeyboard($ticket, true), $ctx);
                }
            }
        } catch (\Throwable $e) {
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
            $ctxBase = array(
                'ticket_id' => (int) $tid,
                'subject' => isset($vars['subject']) ? (string) $vars['subject'] : '',
            );

            if ($statusChanged) {
                if ($this->clientShouldFire('evt_status_changed')) {
                    $ctx = $ctxBase; $ctx['notif_type'] = 'status.client';
                    $this->sendToClient($model, $this->pref('tpl_client_status'), $vars, false, $ctx);
                }
                if ($this->adminShouldFire('evt_status_changed')) {
                    $ctx = $ctxBase; $ctx['notif_type'] = 'status.admin';
                    $this->sendToAdmins($this->pref('tpl_admin_status'), $vars, $this->buildKeyboard($model, true), $ctx);
                }
                // Also sync the ORIGINAL ticket.created card so its keyboard
                // reflects the new state (buttons stripped on close, etc.)
                $this->maybeSyncTicketState($model);
            }
            if ($assigneeChanged) {
                if ($this->adminShouldFire('evt_assignment_changed')) {
                    $ctx = $ctxBase; $ctx['notif_type'] = 'assignment.admin';
                    $this->sendToAdmins($this->pref('tpl_admin_assignment'), $vars, $this->buildKeyboard($model, true), $ctx);
                }
                $this->maybeSyncTicketState($model);
            }
        } catch (\Throwable $e) {
            $this->report($e, array('event' => 'model.updated'));
        }
    }

    /**
     * osTicket fires object.created with $data['type'] IN
     * {closed, reopened, deleted, transferred, overdue}. We map only the
     * lifecycle ones to syncTicketState — 'created' itself is handled by
     * the dedicated ticket.created signal.
     *
     * Design v2 note: we mark this ticket as "state changed" in a
     * per-request bag and let cron() do the actual Telegram edits. This
     * closes the DoS concern (staff HTTP request never blocks on N chats
     * of edit round-trips).
     */
    function onObjectCreated($object, $data = null) {
        if (!($object instanceof Ticket)) { return; }
        if (!$this->pref('sync_ticket_state')) { return; }
        $type = is_array($data) && isset($data['type']) ? (string) $data['type'] : '';
        // Strict whitelist. Unknown types default to skip.
        if (!in_array($type, array('closed', 'reopened', 'deleted', 'transferred'), true)) { return; }
        // Skip the ORM audit noise (a per-column mirror emit some versions of osTicket do).
        if (is_array($data) && !empty($data['orm_audit'])) { return; }
        $this->maybeSyncTicketState($object);
    }

    function onObjectEdited($object, $data = null) {
        if (!($object instanceof Ticket)) { return; }
        if (!$this->pref('sync_ticket_state')) { return; }
        $type = is_array($data) && isset($data['type']) ? (string) $data['type'] : '';
        if (!in_array($type, array('assigned', 'referred'), true)) { return; }
        if (is_array($data) && !empty($data['orm_audit'])) { return; }
        $this->maybeSyncTicketState($object);
    }

    /**
     * Per-request debounce + defer to cron.
     *
     * Multiple signals for the same ticket in one HTTP request (e.g. a
     * setStatus() call fires object.edited + model.updated + object.created
     * cascade) collapse into a single sync. The cron path re-derives
     * current state from the live $ticket at edit time, so ordering of
     * signals doesn't matter.
     */
    private $stateChangedInRequest = array();
    private function maybeSyncTicketState(Ticket $ticket) {
        $tid = (int) $ticket->getId();
        if (!$tid) { return; }
        if (isset($this->stateChangedInRequest[$tid])) { return; }
        $this->stateChangedInRequest[$tid] = true;
        try {
            $this->syncTicketState($ticket);
        } catch (\Throwable $e) {
            $this->report($e, array('event' => 'syncTicketState', 'ticket_id' => $tid));
        }
    }

    // ─── Senders ─────────────────────────────────────────────────────────

    private function sendToClient(Ticket $ticket, $template, array $vars, $forAdmin = false, ?array $context = null) {
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
        $this->dispatchSend($chatId, $text, $kb, $context);
    }

    private function sendToAdmins($template, array $vars, $keyboardMarkup = null, ?array $context = null) {
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
            $this->dispatchSend($chatId, $text, $keyboardMarkup, $context);
        }
    }

    /**
     * Central Telegram send. Handles:
     *   - Global + per-chat rate limiting (persistent bucket).
     *   - INSERT-before-send row reservation so syncTicketState can find
     *     us even if the ticket is deleted between send-ack and DB write.
     *   - Success -> UPDATE the reserved row with the real message_id.
     *   - Failure -> DELETE the reserved row so we don't leak placeholders.
     *
     * $context (optional) = ['ticket_id' => int, 'notif_type' => string,
     *                        'subject' => string]. When provided, the send
     * is tracked in the SentMessageStore so future lifecycle events can
     * edit this message. When null (webhook /help, /whoami, etc.),
     * nothing is persisted.
     */
    private function dispatchSend($chatId, $text, $keyboardMarkup = null, ?array $context = null) {
        $chatId = (int) $chatId;

        // 1. Rate limit before hitting the API. If we can't get a token
        //    within the inline budget, we defer this send to the cron
        //    worker by leaving a pending row (if this is a tracked send)
        //    or skipping entirely.
        if ($this->pref('rate_limit_enabled')) {
            $gotToken = false;
            try {
                $gotToken = $this->rate()->tryAcquire($chatId);
            } catch (\Throwable $e) {
                $gotToken = true; // fail-open on limiter errors
            }
            if (!$gotToken) {
                $this->safe()->warn('Rate-limit deferred send', array(
                    'chat_id'   => $chatId,
                    'ticket_id' => isset($context['ticket_id']) ? (int) $context['ticket_id'] : 0,
                    'notif_type'=> isset($context['notif_type']) ? $context['notif_type'] : null,
                ));
                return;
            }
        }

        // 2. Reserve a placeholder row BEFORE the API call. If the ticket
        //    is deleted while sendMessage is in flight, the deletion
        //    signal will find this row and later editors will decorate it
        //    once we commit.
        $reservedRowId = null;
        if ($context && isset($context['ticket_id']) && isset($context['notif_type'])) {
            try {
                $reservedRowId = $this->messages()->reserve(
                    $chatId,
                    (int) $context['ticket_id'],
                    (string) $context['notif_type'],
                    isset($context['subject']) ? (string) $context['subject'] : ''
                );
            } catch (Exception $e) {
                // never block the send on reservation errors
                $reservedRowId = null;
            }
        }

        // 3. Actual send.
        $opts = array();
        $pm = $this->pref('parse_mode');
        if ($pm) { $opts['parse_mode'] = $pm; }
        if ($this->pref('disable_web_page_preview')) { $opts['disable_web_page_preview'] = true; }
        if ($this->pref('disable_notification'))     { $opts['disable_notification'] = true; }
        if (is_array($keyboardMarkup))               { $opts['reply_markup'] = $keyboardMarkup; }

        $res = $this->api()->sendMessage($chatId, $text, $opts);

        // 4. Success -> commit; failure -> abort.
        if (!empty($res['ok']) && !empty($res['body']['result']['message_id'])) {
            $messageId = (int) $res['body']['result']['message_id'];
            if ($reservedRowId) {
                $this->messages()->commit($reservedRowId, $messageId);
            }
            $this->log('info', 'sendMessage ok', array('chat_id' => $chatId, 'status' => $res['status'], 'message_id' => $messageId));
        } else {
            if ($reservedRowId) {
                $this->messages()->abort($reservedRowId);
            }
            $this->log('error', 'sendMessage failed', array(
                'chat_id' => $chatId,
                'status'  => isset($res['status']) ? $res['status'] : 0,
                'error'   => isset($res['error']) ? $res['error'] : '(unknown)',
            ));
            $this->safe()->error('Telegram sendMessage failed', array(
                'chat_id'    => $chatId,
                'ticket_id'  => isset($context['ticket_id']) ? (int) $context['ticket_id'] : 0,
                'notif_type' => isset($context['notif_type']) ? $context['notif_type'] : null,
                'status'     => isset($res['status']) ? (string) $res['status'] : '0',
                'error'      => isset($res['error']) ? $res['error'] : '',
            ));
        }
    }

    // ─── Ticket state sync (Design v2) ───────────────────────────────────

    /**
     * Fan-out edits to every previously-sent Telegram card for this
     * ticket. Called from onObjectCreated/onObjectEdited (via
     * maybeSyncTicketState) AND from callback handlers after inline
     * action. Idempotent thanks to 'not_modified' handling.
     *
     * The 2026-08-08 review flagged that doing all this synchronously
     * inside a signal handler blocks the staff HTTP request. Mitigation:
     * we cap wall-time at 1.5s inline; anything beyond that is left for
     * the next cron() pass (findByTicket keeps returning the row since
     * we haven't edited it yet — natural retry).
     */
    private function syncTicketState(Ticket $ticket) {
        if (!$this->pref('sync_ticket_state')) { return; }

        $ticketId = (int) $ticket->getId();
        if (!$ticketId) { return; }

        $rows = $this->messages()->findByTicket($ticketId);
        if (!$rows) { return; }

        // Snapshot the state once — every edit uses the same rendering.
        $state = $this->deriveCurrentState($ticket);

        $deadlineMs = (int) (microtime(true) * 1000) + 1500;
        $edited = 0;
        $deferred = 0;

        foreach ($rows as $row) {
            if ((int) (microtime(true) * 1000) >= $deadlineMs) {
                // Wall-time budget spent; leave the rest for next cron().
                $deferred++;
                continue;
            }
            $this->editOneMessage($row, $state);
            $edited++;
        }

        if ($deferred > 0) {
            $this->safe()->warn('syncTicketState deferred edits to cron', array(
                'ticket_id' => $ticketId,
                'edited' => $edited,
                'deferred' => $deferred,
            ));
        }
    }

    /**
     * Re-derive current visible state of the ticket at edit time. The
     * signal's declared $data['type'] is NOT trusted — ordering races
     * between close+delete can flip the "type" arbitrarily. Whatever the
     * ticket IS right now (deleted / closed / open / assigned) wins.
     */
    private function deriveCurrentState(Ticket $ticket) {
        $out = array(
            'kind' => 'unknown',    // deleted | closed | reopened | assigned | open
            'label' => '',           // "ELIMINADO", "CERRADO", etc.
            'emoji' => '',
            'actor' => $this->currentActorName(),
            'ticket_number' => '',
            'assignee' => '',
        );
        try {
            $out['ticket_number'] = (string) $ticket->getNumber();
        } catch (Exception $e) { /* ignore */ }

        // Deleted: Ticket::isDeleted() OR status name/state matches.
        $deleted = false;
        try {
            if (method_exists($ticket, 'isDeleted') && $ticket->isDeleted()) { $deleted = true; }
            if (!$deleted) {
                $st = method_exists($ticket, 'getStatus') ? $ticket->getStatus() : null;
                if ($st && method_exists($st, 'getState') && $st->getState() === 'deleted') { $deleted = true; }
            }
        } catch (Exception $e) { /* fall through */ }

        if ($deleted) {
            $out['kind'] = 'deleted';
            $out['label'] = 'ELIMINADO';
            $out['emoji'] = '&#128465;'; // 🗑 (U+1F5D1 — 4-byte, use HTML entity for utf8mb4-safe storage)
            return $out;
        }

        try {
            $st = method_exists($ticket, 'getStatus') ? $ticket->getStatus() : null;
            if ($st) {
                $state = method_exists($st, 'getState') ? (string) $st->getState() : '';
                if ($state === 'closed' || $state === 'resolved') {
                    $out['kind'] = 'closed';
                    $out['label'] = 'CERRADO';
                    $out['emoji'] = '&#9989;'; // ✅
                } elseif ($state === 'open') {
                    $out['kind'] = 'open';
                    // Assigned? only decorate as "assigned" if there's a staff.
                    try {
                        $st2 = method_exists($ticket, 'getStaff') ? $ticket->getStaff() : null;
                        if ($st2) {
                            $out['kind'] = 'assigned';
                            $out['label'] = 'ASIGNADO';
                            $out['emoji'] = '&#128100;'; // 👤
                            $out['assignee'] = (string) $st2->getName();
                        }
                    } catch (Exception $e) { /* ignore */ }
                }
            }
        } catch (Exception $e) { /* ignore */ }

        return $out;
    }

    /**
     * Best-effort resolve the acting staff for the current request.
     * Falls back to "sistema" for cron / API / unauthenticated paths.
     */
    private function currentActorName() {
        if (isset($GLOBALS['thisstaff']) && is_object($GLOBALS['thisstaff'])
                && method_exists($GLOBALS['thisstaff'], 'getName')) {
            try {
                $n = (string) $GLOBALS['thisstaff']->getName();
                if ($n !== '') { return $n; }
            } catch (Exception $e) { /* ignore */ }
        }
        return 'sistema';
    }

    /**
     * Build the decorated text for a card given the current state and
     * the original subject_snapshot from the DB row.
     *
     * For 'deleted' we DO NOT include the subject (privacy /
     * GDPR concern from the 2026-08-08 review): rendering
     * "<s>#42 — the ticket subject</s>" would re-broadcast the subject
     * back through Telegram servers if the ticket was deleted for
     * legal-erasure reasons. Use a neutral "[eliminado]" placeholder.
     */
    private function decorateForState(array $state, $subjectSnapshot) {
        $num = htmlspecialchars((string) $state['ticket_number'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $actor = htmlspecialchars((string) $state['actor'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        switch ($state['kind']) {
            case 'deleted':
                return $state['emoji'] . ' <b>' . $state['label'] . '</b>'
                     . "\n" . '<s>#' . $num . ' — [eliminado]</s>'
                     . "\n" . '<i>por ' . $actor . '</i>';

            case 'closed':
                $subj = htmlspecialchars((string) $subjectSnapshot, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return $state['emoji'] . ' <b>' . $state['label'] . '</b>'
                     . "\n" . '<s>#' . $num . ' — ' . $subj . '</s>'
                     . "\n" . '<i>por ' . $actor . '</i>';

            case 'assigned':
                $subj = htmlspecialchars((string) $subjectSnapshot, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $who  = htmlspecialchars((string) $state['assignee'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return $state['emoji'] . ' <b>' . $state['label'] . '</b>'
                     . "\n" . '#' . $num . ' — ' . $subj
                     . "\n" . '<i>asignado a ' . $who . '</i>';

            default:
                // No decoration for "open" (nothing to communicate).
                return null;
        }
    }

    /**
     * Edit a single row's Telegram card. Handles all failure modes
     * (not_modified, gone, expired, rate_limited, transport).
     */
    private function editOneMessage(array $row, array $state) {
        $chatId    = (int) $row['chat_id'];
        $messageId = (int) $row['message_id'];
        $subject   = (string) (isset($row['subject_snapshot']) ? $row['subject_snapshot'] : '');

        // Delete mode privacy toggle — if sync_on_delete is off, strip
        // the keyboard only (no text change, no re-broadcast).
        $stripKeyboardOnly = ($state['kind'] === 'deleted') && !$this->pref('sync_on_delete');

        // Rate limit per-chat before hitting the API.
        if ($this->pref('rate_limit_enabled')) {
            try {
                if (!$this->rate()->tryAcquire($chatId)) {
                    // Deferred to next cron pass. Do NOT increment failure_count.
                    return;
                }
            } catch (Exception $e) { /* fail-open */ }
        }

        if ($stripKeyboardOnly) {
            $res = $this->api()->editMessageReplyMarkup($chatId, $messageId, array('inline_keyboard' => array()));
        } else {
            $text = $this->decorateForState($state, $subject);
            if ($text === null) { return; } // 'open' state — nothing to show
            $res = $this->api()->editMessageText($chatId, $messageId, $text);
        }

        $kind = isset($res['error_kind']) ? $res['error_kind'] : null;
        if (!empty($res['ok']) || $kind === 'not_modified') {
            return; // success or benign no-op
        }
        if ($kind === 'gone' || $kind === 'expired') {
            $count = $this->messages()->recordFailure($chatId, $messageId);
            $this->safe()->warn('editMessage failed (gone/expired)', array(
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'ticket_id' => isset($row['ticket_id']) ? (int) $row['ticket_id'] : 0,
                'notif_type' => isset($row['notif_type']) ? $row['notif_type'] : null,
                'error_kind' => $kind,
                'failure_count' => $count,
            ));
            return;
        }
        if ($kind === 'rate_limited' || $kind === 'transport') {
            // Defer to next sync attempt — do NOT increment failure_count
            // (transient by definition).
            return;
        }
        // Unknown 4xx (parse error, etc.) — log but don't kill the row.
        $this->safe()->warn('editMessage failed (other)', array(
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'ticket_id' => isset($row['ticket_id']) ? (int) $row['ticket_id'] : 0,
            'error_kind' => $kind,
            'status' => isset($res['status']) ? (string) $res['status'] : '0',
            'error' => isset($res['error']) ? $res['error'] : '',
        ));
    }

    /**
     * osTicket calls cron() on every Plugin during its cron sweep.
     * Runs the sent-messages / rate-bucket purge at most once per hour
     * via an atomic ostt4_config swap.
     */
    function cron() {
        $lockKey = 'telegram-bot.last_purge';
        $now = time();
        // Atomic-ish: read-then-write via a config helper. If two cron
        // runs collide, the second will find last_purge already updated
        // and skip. Small race window is acceptable — worst case is a
        // second purge pass with 0 rows affected.
        try {
            $cfg = $this->cfg();
            $last = (int) $cfg->get($lockKey);
            if ($last && ($now - $last) < 3600) { return; }
            $cfg->set($lockKey, $now);
            $this->messages()->purgeExpired();
            $this->rate()->purgeInactive();
        } catch (\Throwable $e) {
            $this->report($e, array('event' => 'cron.purge'));
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

    /**
     * Inline-keyboard callback dispatcher. Telegram sends a callback_query
     * Update when the user taps a callback_data button. We:
     *   1) Resolve the staff member from chat_id (must be linked).
     *   2) Parse the callback_data ("action:ticket_id" form).
     *   3) Execute the action on the ticket.
     *   4) answerCallbackQuery() with a short toast text — REQUIRED so
     *      the spinner on the button stops.
     *   5) editMessageReplyMarkup() to disable the consumed buttons,
     *      so the same action can't be triggered twice from the same msg.
     */
    private function handleCallbackQuery(array $cq) {
        $cqId   = isset($cq['id']) ? (string) $cq['id'] : '';
        $data   = isset($cq['data']) ? (string) $cq['data'] : '';
        $chatId = isset($cq['message']['chat']['id']) ? (int) $cq['message']['chat']['id'] : 0;
        $msgId  = isset($cq['message']['message_id']) ? (int) $cq['message']['message_id'] : 0;
        if ($cqId === '' || $data === '' || $chatId === 0) {
            return;
        }

        // Staff resolution: chat_id must be linked to a Staff record. If a
        // customer chat tries to press an admin button (shouldn't happen
        // since admin buttons are only rendered on admin notifications,
        // but defense in depth), we refuse.
        $staffId = $this->resolveStaffByChatId($chatId);
        if ($staffId <= 0) {
            $this->api()->answerCallbackQuery($cqId, 'No autorizado. Vincule su cuenta primero (/start).', array('show_alert' => true));
            return;
        }

        // Data format: "action:ticket_id". Reject anything else.
        if (!preg_match('/^([a-z_]+):(\d+)$/', $data, $m)) {
            $this->api()->answerCallbackQuery($cqId, 'Acción no reconocida.');
            return;
        }
        $action = $m[1];
        $ticketId = (int) $m[2];

        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            // The ticket was deleted out-of-band (direct SQL, admin cleanup,
            // Ticket::delete outside a signal path). Decorate THIS card
            // in-place using the callback's own chat_id/message_id — no DB
            // lookup needed. Also purge any sibling rows for this ticket
            // so future callbacks on other admin cards also short-circuit.
            $this->api()->answerCallbackQuery($cqId, 'Ticket ya no existe.', array('show_alert' => true));
            try {
                $this->api()->editMessageText(
                    $chatId, $msgId,
                    '&#128465; <b>ELIMINADO</b>' . "\n" . '<s>#' . (int) $ticketId . ' — [eliminado]</s>',
                    array()
                );
            } catch (Exception $e) { /* best-effort */ }
            try {
                $this->messages()->deleteForTicket($ticketId);
            } catch (Exception $e) { /* best-effort */ }
            return;
        }

        switch ($action) {
            case 'assign':
                $this->callbackAssign($cqId, $chatId, $msgId, $ticket, $staffId);
                break;
            case 'close':
                $this->callbackClose($cqId, $chatId, $msgId, $ticket, $staffId);
                break;
            default:
                $this->api()->answerCallbackQuery($cqId, 'Acción no soportada.');
                break;
        }
    }

    /**
     * Resolve a Staff.staff_id from a Telegram chat_id via the
     * ostt4_telegram_staff_links table. Returns 0 when not linked.
     */
    private function resolveStaffByChatId($chatId) {
        try {
            $sid = $this->links()->staffIdForChat((int) $chatId);
            return $sid !== null ? (int) $sid : 0;
        } catch (Exception $e) {
            $this->log('warning', 'resolveStaffByChatId failed', array('exception' => $e->getMessage()));
            return 0;
        }
    }

    /**
     * "Asignar a mí" — assign the ticket to the staff member who pressed
     * the button. We use Ticket::assignToStaff() so all hooks/notifications
     * fire as if it were done from the web UI.
     *
     * Auth: a linked staff member could spoof callback_data to act on a
     * ticket they couldn't normally touch in the web UI (their button was
     * never rendered for that ticket). So we explicitly check assign
     * permission against the resolved Staff before doing anything.
     */
    private function callbackAssign($cqId, $chatId, $msgId, Ticket $ticket, $staffId) {
        try {
            $staff = Staff::lookup((int) $staffId);
            if (!$staff || !$staff->isActive()) {
                $this->api()->answerCallbackQuery($cqId, 'Tu cuenta de staff está inactiva.', array('show_alert' => true));
                return;
            }
            if (!$this->staffMayActOn($staff, $ticket, 'assign')) {
                $this->api()->answerCallbackQuery($cqId, 'No tienes permiso para asignar este ticket.', array('show_alert' => true));
                return;
            }
            $errors = array();
            $ok = $ticket->assignToStaff($staffId, __('Asignado desde Telegram'), false);
            if (!$ok) {
                $this->api()->answerCallbackQuery($cqId, 'No se pudo asignar.', array('show_alert' => true));
                return;
            }
            $name = $staff->getName();
            $this->api()->answerCallbackQuery($cqId, '✓ Asignado a ' . (string) $name);
            // Sync fan-out (decorates OTHER admins' cards too). onModelUpdated
            // will also fire via Ticket::setStaffId → dirty flags, but
            // maybeSyncTicketState is debounced per-request so no dup edits.
            $this->maybeSyncTicketState($ticket);
            // Local card (this chat's) — clear its keyboard immediately as
            // acknowledgment. syncTicketState above will decorate it too via
            // the persisted row.
            $this->clearKeyboardOnMessage($chatId, $msgId);
        } catch (\Throwable $e) {
            $this->report($e, array('event' => 'callback.assign'));
            $this->api()->answerCallbackQuery($cqId, 'Error al asignar (revisa logs).', array('show_alert' => true));
        }
    }

    /**
     * "Cerrar ticket" — set status=Closed via Ticket::setStatus(). Posts an
     * internal note attributed to the staff member who pressed the button.
     * Same auth model as callbackAssign — verify permission first.
     */
    private function callbackClose($cqId, $chatId, $msgId, Ticket $ticket, $staffId) {
        try {
            $staff = Staff::lookup((int) $staffId);
            if (!$staff || !$staff->isActive()) {
                $this->api()->answerCallbackQuery($cqId, 'Tu cuenta de staff está inactiva.', array('show_alert' => true));
                return;
            }
            if (!$this->staffMayActOn($staff, $ticket, 'close')) {
                $this->api()->answerCallbackQuery($cqId, 'No tienes permiso para cerrar este ticket.', array('show_alert' => true));
                return;
            }
            $closedStatus = TicketStatus::lookup(array('state' => 'closed'));
            if (!$closedStatus) {
                $this->api()->answerCallbackQuery($cqId, 'No hay estado "Closed" configurado.', array('show_alert' => true));
                return;
            }
            $errors = array();
            $ok = $ticket->setStatus($closedStatus->getId(), __('Cerrado desde Telegram por ') . $staff->getName(), $errors, false);
            if (!$ok) {
                $this->api()->answerCallbackQuery($cqId, 'No se pudo cerrar.', array('show_alert' => true));
                return;
            }
            $this->api()->answerCallbackQuery($cqId, '✓ Ticket cerrado');
            $this->maybeSyncTicketState($ticket);
            $this->clearKeyboardOnMessage($chatId, $msgId);
        } catch (\Throwable $e) {
            $this->report($e, array('event' => 'callback.close'));
            $this->api()->answerCallbackQuery($cqId, 'Error al cerrar (revisa logs).', array('show_alert' => true));
        }
    }

    /**
     * Authorization gate for callback actions. Linked chat_id alone isn't
     * enough — callback_data is attacker-controllable (forwarded message,
     * spoofed via API). Require the resolved staff to have the matching
     * ticket-level permission via Ticket::checkStaffPerm() / Staff role
     * lookup. Admins always pass.
     *
     * Returns false on any error / missing constant, so we fail closed.
     */
    private function staffMayActOn(Staff $staff, Ticket $ticket, $action) {
        try {
            if (method_exists($staff, 'isAdmin') && $staff->isAdmin()) {
                return true;
            }
            // Map our action to osTicket's permission constants when available.
            // Different osTicket versions name these differently — guard each.
            $perm = null;
            if ($action === 'close' && defined('Ticket::PERM_CLOSE')) {
                $perm = Ticket::PERM_CLOSE;
            } elseif ($action === 'assign' && defined('Ticket::PERM_ASSIGN')) {
                $perm = Ticket::PERM_ASSIGN;
            }
            if ($perm !== null && method_exists($ticket, 'checkStaffPerm')) {
                return (bool) $ticket->checkStaffPerm($staff, $perm);
            }
            // Fallback: deny non-admin staff when we can't verify the perm.
            // Better to surface "No autorizado" than to silently authorize.
            return false;
        } catch (Exception $e) {
            $this->log('warning', 'staffMayActOn failed', array('exception' => $e->getMessage()));
            return false;
        }
    }

    /**
     * Strip the inline keyboard from a previously sent message so the user
     * can't press the same button twice. Best-effort: ignores errors (e.g.
     * if Telegram already deleted the original message after 48h).
     */
    private function clearKeyboardOnMessage($chatId, $msgId) {
        if ($chatId === 0 || $msgId === 0) { return; }
        $this->api()->editMessageReplyMarkup($chatId, $msgId, array('inline_keyboard' => array()));
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

    /** @var TgSentMessageStore|null Cached per-request. */
    private $messages = null;
    private function messages() {
        if ($this->messages === null) { $this->messages = new TgSentMessageStore(); }
        return $this->messages;
    }

    /** @var TgRateLimiter|null Cached per-request. */
    private $rate = null;
    private function rate() {
        if ($this->rate === null) { $this->rate = new TgRateLimiter(); }
        return $this->rate;
    }

    /** @var TgSafeSentry|null Cached per-request. */
    private $safe = null;
    private function safe() {
        if ($this->safe === null) { $this->safe = new TgSafeSentry($this->sentry); }
        return $this->safe;
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
        // FIX 2026-06-11: $entry->getPoster() retorna STRING (nombre), no objeto.
        // Determinar tipo via columnas staff_id / user_id directamente.
        try {
            $staffId = (int)$entry->staff_id;
            $userId = (int)$entry->user_id;
            if ($staffId > 0) { return "staff"; }
            if ($userId > 0) { return "user"; }
            // fallback: si poster es string sin user_id ni staff_id, asumir system
        } catch (Exception $e) {}
        // legacy path (en caso de que getPoster sea sobrescrito en algún future osTicket):
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

        // Row 1: URL buttons (open ticket in web UI).
        $kb->addRow();
        if ($this->pref('btn_view_ticket')) {
            $label = trim((string) $this->pref('btn_view_ticket_label')) ?: 'View ticket';
            $kb->urlButton($label, $base . '/scp/tickets.php?id=' . $tid);
        }
        if ($forAdmin && $this->pref('btn_reply')) {
            $label = trim((string) $this->pref('btn_reply_label')) ?: 'Reply';
            $kb->urlButton($label, $base . '/scp/tickets.php?id=' . $tid . '#reply');
        }

        // Row 2: callback action buttons (admin only — clients can't act).
        // Webhook routes these to handleCallbackQuery -> close/assign/etc.
        if ($forAdmin) {
            $kb->addRow();
            if ($this->pref('btn_assign_me')) {
                $label = trim((string) $this->pref('btn_assign_me_label')) ?: 'Asignar a mí';
                $kb->callbackButton($label, 'assign:' . $tid);
            }
            if ($this->pref('btn_close_ticket')) {
                $label = trim((string) $this->pref('btn_close_ticket_label')) ?: 'Cerrar ticket';
                $kb->callbackButton($label, 'close:' . $tid);
            }
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
