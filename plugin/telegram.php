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
    /** @var EvoSentryReporter */
    private $sentry;
    /** @var array<string,bool> per-(ticket,kind) request dedup */
    private $sentInRequest = array();

    // ─── Lifecycle ───────────────────────────────────────────────────────

    function bootstrap() {
        $cfg = $this->getConfig();

        $this->sentry = new EvoSentryReporter($cfg->get('sentry_dsn'));
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
        $cfg = $this->getConfig();
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
     * Called from the staff UI / user profile flow.
     */
    public function generateLinkUrl($userId) {
        $cfg = $this->getConfig();
        $bot = trim((string) $cfg->get('bot_username'));
        if ($bot === '') {
            return null;
        }
        $token = $this->links()->issueToken((int) $userId);
        return 'https://t.me/' . rawurlencode($bot) . '?start=' . rawurlencode($token);
    }

    // ─── Signal handlers ─────────────────────────────────────────────────

    function onTicketCreated($ticket) {
        $cfg = $this->getConfig();
        try {
            $vars = $this->ticketVars($ticket);
            $vars['message'] = $this->firstMessage($ticket);

            if ($this->clientShouldFire('evt_ticket_created')) {
                $this->sendToClient($ticket, $cfg->get('tpl_client_created'), $vars, /*adminKb*/ false);
            }
            if ($this->adminShouldFire('evt_ticket_created')) {
                $this->sendToAdmins($cfg->get('tpl_admin_created'), $vars, $this->buildKeyboard($ticket, true));
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'ticket.created'));
        }
    }

    function onThreadEntryCreated($entry) {
        $cfg = $this->getConfig();
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
            $body = TgFormatter::truncate($this->bodyToText($entry->getBody(), $cfg->get('parse_mode')), 2500);
            $vars['message'] = $body;

            if ($isStaff) {
                if ($this->clientShouldFire('evt_staff_reply')) {
                    $this->sendToClient($ticket, $cfg->get('tpl_client_staff_reply'), $vars, false);
                }
                if ($this->adminShouldFire('evt_staff_reply')) {
                    $tpl = $cfg->get('tpl_admin_staff_reply');
                    if ($tpl === null || $tpl === '') {
                        $tpl = $cfg->get('tpl_admin_user_reply');
                    }
                    $this->sendToAdmins($tpl, $vars, $this->buildKeyboard($ticket, true));
                }
            }
            if ($isUser) {
                if ($this->adminShouldFire('evt_user_reply')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_user_reply'), $vars, $this->buildKeyboard($ticket, true));
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'threadentry.created'));
        }
    }

    function onModelUpdated($model) {
        if (!($model instanceof Ticket)) { return; }
        $cfg = $this->getConfig();
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
                    $this->sendToClient($model, $cfg->get('tpl_client_status'), $vars, false);
                }
                if ($this->adminShouldFire('evt_status_changed')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_status'), $vars, $this->buildKeyboard($model, true));
                }
            }
            if ($assigneeChanged) {
                if ($this->adminShouldFire('evt_assignment_changed')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_assignment'), $vars, $this->buildKeyboard($model, true));
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'model.updated'));
        }
    }

    // ─── Senders ─────────────────────────────────────────────────────────

    private function sendToClient(Ticket $ticket, $template, array $vars, $forAdmin = false) {
        $cfg = $this->getConfig();

        // Honor opt-in.
        if ($cfg->get('respect_user_opt_in')) {
            $optIn = $this->userOptedIn($ticket);
            if ($optIn === false) {
                $this->log('info', 'Customer opted out — skipping ticket #' . $vars['ticket_number']);
                return;
            }
        }

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
        $cfg = $this->getConfig();
        $raw = (string) $cfg->get('admin_chat_ids');
        $list = array();
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^-?\d{4,20}$/', $line)) {
                $list[] = $line;
            }
        }
        if (!$list) {
            $this->log('debug', 'notify_admins is on but no admin chat IDs configured');
            return;
        }

        $text = TgFormatter::render($template, $this->escapeVarsForParseMode($vars));
        $text = TgFormatter::truncate($text, 3500);

        $delayMs = max(0, (int) $cfg->get('send_delay_ms'));
        foreach ($list as $i => $chatId) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $this->dispatchSend($chatId, $text, $keyboardMarkup);
        }
    }

    private function dispatchSend($chatId, $text, $keyboardMarkup = null) {
        $cfg = $this->getConfig();
        $opts = array();
        $pm = $cfg->get('parse_mode');
        if ($pm) { $opts['parse_mode'] = $pm; }
        if ($cfg->get('disable_web_page_preview')) { $opts['disable_web_page_preview'] = true; }
        if ($cfg->get('disable_notification'))     { $opts['disable_notification'] = true; }
        if (is_array($keyboardMarkup))             { $opts['reply_markup'] = $keyboardMarkup; }

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
            $this->reply($chatId, 'Hi! Use the "Link Telegram" button on your osTicket profile to connect your account.');
            return;
        }

        $userId = $this->links()->consumeToken($arg);
        if ($userId === null) {
            $this->reply($chatId, 'Linking token is invalid or expired. Please request a new one from your osTicket profile.');
            return;
        }
        $this->links()->link($userId, $chatId);
        $this->log('info', 'Linked user', array('user_id' => $userId, 'chat_id' => $chatId));
        $this->reply($chatId, '✅ Your Telegram is now linked to your osTicket account. You\'ll receive ticket updates here. Use /unlink to disconnect.');
    }

    private function handleUnlink($chatId) {
        $userId = $this->links()->userIdForChat($chatId);
        if ($userId === null) {
            $this->reply($chatId, 'This chat is not linked to any osTicket account.');
            return;
        }
        $this->links()->unlinkByChat($chatId);
        $this->log('info', 'Unlinked user', array('user_id' => $userId, 'chat_id' => $chatId));
        $this->reply($chatId, '🔌 Unlinked. You will no longer receive ticket updates here.');
    }

    private function handleStatus($chatId) {
        $userId = $this->links()->userIdForChat($chatId);
        if ($userId === null) {
            $this->reply($chatId, 'This chat is not linked.');
            return;
        }
        $this->reply($chatId, 'Linked to osTicket user #' . $userId . '.');
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
        $cfg = $this->getConfig();
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
            $ttl = max(60, (int) ($this->getConfig()->get('link_token_ttl') ?: 900));
            $this->links = new TgUserLinkStore($ttl);
        }
        return $this->links;
    }

    // ─── Decision helpers ────────────────────────────────────────────────

    private function anyOn(/* ...keys */) {
        $cfg = $this->getConfig();
        foreach (func_get_args() as $k) {
            if ($cfg->get($k)) { return true; }
        }
        return false;
    }

    private function clientShouldFire($eventKey) {
        $cfg = $this->getConfig();
        return $cfg->get('notify_clients') && $cfg->get($eventKey . '__client');
    }

    private function adminShouldFire($eventKey) {
        $cfg = $this->getConfig();
        return $cfg->get('notify_admins') && $cfg->get($eventKey . '__admin');
    }

    private function markOnce($ticketId, $kind) {
        $key = $ticketId . ':' . $kind;
        if (isset($this->sentInRequest[$key])) { return true; }
        $this->sentInRequest[$key] = true;
        return false;
    }

    // ─── Resolve client chat_id ──────────────────────────────────────────

    private function resolveClientChatId(Ticket $ticket) {
        $cfg = $this->getConfig();
        try {
            $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
            if (!$owner) { return null; }
            $userId = method_exists($owner, 'getId') ? (int) $owner->getId() : 0;

            // Strategy 1: deep-link store.
            if ($cfg->get('allow_deeplink_linking') && $userId) {
                $chat = $this->links()->chatIdForUser($userId);
                if ($chat) { return $chat; }
            }
            // Strategy 2: manual field on user profile.
            if ($cfg->get('allow_manual_chat_id')) {
                $var = trim((string) $cfg->get('manual_chat_id_field_variable'));
                if ($var !== '') {
                    $v = $this->readUserCustomField($owner, $var);
                    if ($v !== null && $v !== '' && !is_array($v)) {
                        $digits = preg_replace('/[^0-9-]/', '', (string) $v);
                        if ($digits !== '' && preg_match('/^-?\d{4,20}$/', $digits)) {
                            return $digits;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
        return null;
    }

    // ─── Opt-in (mirrors Evolution plugin) ───────────────────────────────

    private function userOptedIn(Ticket $ticket) {
        $cfg = $this->getConfig();
        $variable = trim((string) $cfg->get('opt_in_field_variable'));
        if ($variable === '') { $variable = 'telegram_opt_in'; }
        $defaultWhenAbsent = (bool) $cfg->get('opt_in_default_when_absent');

        try {
            $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
            if (!$owner) {
                return $defaultWhenAbsent ? null : false;
            }
            $value = $this->readUserCustomField($owner, $variable);
            if ($value === null) {
                return $defaultWhenAbsent ? null : false;
            }
            return $this->coerceBool($value);
        } catch (Exception $e) {
            return null;
        }
    }

    private function readUserCustomField($user, $variable) {
        if (method_exists($user, 'getForms')) {
            try {
                $forms = $user->getForms();
                if ($forms) {
                    foreach ($forms as $entry) {
                        $v = $this->extractFieldFromEntry($entry, $variable);
                        if ($v !== null) { return $v; }
                    }
                }
            } catch (Exception $e) {}
        }
        if (method_exists($user, 'getDynamicData')) {
            try {
                $entries = $user->getDynamicData();
                if ($entries) {
                    foreach ($entries as $entry) {
                        $v = $this->extractFieldFromEntry($entry, $variable);
                        if ($v !== null) { return $v; }
                    }
                }
            } catch (Exception $e) {}
        }
        if (method_exists($user, 'getInfo')) {
            try {
                $info = $user->getInfo();
                if (is_array($info) && array_key_exists($variable, $info)) {
                    return $info[$variable];
                }
            } catch (Exception $e) {}
        }
        return null;
    }

    private function extractFieldFromEntry($entry, $variable) {
        if (!is_object($entry)) { return null; }
        if (method_exists($entry, 'getField')) {
            try {
                $field = $entry->getField($variable);
                if ($field && method_exists($field, 'getClean')) {
                    return $field->getClean();
                }
                if ($field && method_exists($field, 'getValue')) {
                    return $field->getValue();
                }
            } catch (Exception $e) {}
        }
        if (method_exists($entry, 'getAnswers')) {
            try {
                foreach ($entry->getAnswers() as $answer) {
                    if (!is_object($answer)) { continue; }
                    $field = method_exists($answer, 'getField') ? $answer->getField() : null;
                    $name = $field && method_exists($field, 'get') ? $field->get('name') : null;
                    if ($name === $variable) {
                        if (method_exists($answer, 'getValue')) {
                            return $answer->getValue();
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        return null;
    }

    private function coerceBool($value) {
        if (is_bool($value)) { return $value; }
        if (is_numeric($value)) { return ((int) $value) !== 0; }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '' || $v === '0' || $v === 'false' || $v === 'no' || $v === 'off') { return false; }
            return true;
        }
        if (is_array($value)) { return !empty($value); }
        return (bool) $value;
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
        $mode = $this->getConfig()->get('parse_mode');
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
        $base = trim((string) $this->getConfig()->get('base_url'));
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
            $mode = $this->getConfig()->get('parse_mode');
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
        $cfg = $this->getConfig();
        $base = trim((string) $cfg->get('base_url'));
        if ($base === '') {
            return null;
        }
        $base = rtrim($base, '/');
        $tid  = (int) $ticket->getId();
        $kb   = new TgInlineKeyboard();
        $kb->addRow();

        if ($cfg->get('btn_view_ticket')) {
            $label = trim((string) $cfg->get('btn_view_ticket_label')) ?: 'View ticket';
            $kb->urlButton($label, $base . '/scp/tickets.php?id=' . $tid);
        }
        if ($forAdmin && $cfg->get('btn_reply')) {
            $label = trim((string) $cfg->get('btn_reply_label')) ?: 'Reply';
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
        $cfg = $this->getConfig();
        $debug = (bool) $cfg->get('debug_mode');
        if (!$debug && !in_array($level, array('error', 'warning'), true)) {
            return;
        }
        $line = '[TelegramBotNotifications][' . strtoupper($level) . '] ' . $msg;
        if (!empty($ctx)) {
            $line .= ' ' . json_encode(EvoLogRedactor::context($ctx));
        }
        error_log($line);
    }
}
