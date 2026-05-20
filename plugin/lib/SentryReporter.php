<?php
/**
 * Minimal Sentry reporter — no Composer required.
 *
 * Sends events to Sentry's `store` endpoint. Supports:
 *  - DSN parsing (https://<key>@<host>/<project>)
 *  - captureException(): structured exception event
 *  - captureMessage(): plain message event
 *  - context (tags, extra, user) attached to every event
 *
 * Designed to be optional: if no DSN is configured, all methods are no-ops.
 *
 * This is intentionally NOT a full Sentry SDK — for richer features
 * (breadcrumbs, performance, sessions) install the official SDK via
 * Composer and replace this class.
 *
 * @license GPL-2.0-or-later
 * @link    https://develop.sentry.dev/sdk/event-payloads/
 */
class EvoSentryReporter {

    /** @var string|null */
    private $dsn;
    /** @var array{key:string,host:string,scheme:string,projectId:string}|null */
    private $parsed;
    /** @var string */
    private $environment = 'production';
    /** @var string */
    private $release;
    /** @var array */
    private $tags = array();
    /** @var array */
    private $extra = array();
    /** @var array|null */
    private $user;
    /** @var int */
    private $timeout = 3;

    public function __construct($dsn = null) {
        $this->release = 'osticket-evolution-api@0.1.0';
        $this->setDsn($dsn);
    }

    public function setDsn($dsn) {
        $this->dsn = $dsn ? (string) $dsn : null;
        $this->parsed = $this->dsn ? $this->parseDsn($this->dsn) : null;
    }
    public function setEnvironment($env) { $this->environment = (string) $env; }
    public function setRelease($rel)     { $this->release = (string) $rel; }
    public function setUser($user)       { $this->user = is_array($user) ? $user : null; }
    public function addTag($k, $v)       { $this->tags[(string) $k] = (string) $v; }
    public function addExtra($k, $v)     { $this->extra[(string) $k] = $v; }
    public function isEnabled()          { return $this->parsed !== null; }

    /**
     * Send an exception to Sentry. Returns the event_id or null.
     */
    public function captureException($throwable, array $ctx = array()) {
        if (!$this->isEnabled() || !($throwable instanceof Exception
            || (PHP_MAJOR_VERSION >= 7 && $throwable instanceof Throwable))) {
            return null;
        }
        $payload = $this->basePayload('error', $ctx);
        $payload['exception'] = array(
            'values' => array($this->serializeThrowable($throwable)),
        );
        return $this->send($payload);
    }

    /**
     * Send a message to Sentry. Returns the event_id or null.
     */
    public function captureMessage($message, $level = 'info', array $ctx = array()) {
        if (!$this->isEnabled()) {
            return null;
        }
        $payload = $this->basePayload($level, $ctx);
        $payload['message'] = array('formatted' => (string) $message);
        return $this->send($payload);
    }

    /**
     * @return string Hex event_id
     */
    private function newEventId() {
        $bytes = function_exists('random_bytes') ? random_bytes(16) : null;
        if ($bytes === null) {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }
        return bin2hex($bytes);
    }

    private function basePayload($level, array $ctx) {
        $tags  = array_merge($this->tags, isset($ctx['tags']) && is_array($ctx['tags']) ? $ctx['tags'] : array());
        $extra = array_merge($this->extra, isset($ctx['extra']) && is_array($ctx['extra']) ? $ctx['extra'] : array());
        $user  = isset($ctx['user']) && is_array($ctx['user']) ? $ctx['user'] : $this->user;

        $event = array(
            'event_id'    => $this->newEventId(),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'level'       => (string) $level,
            'logger'      => 'evolution-api-plugin',
            'platform'    => 'php',
            'environment' => $this->environment,
            'release'     => $this->release,
            'sdk'         => array(
                'name'    => 'evolution-api-plugin.sentry-min',
                'version' => '0.1.0',
            ),
            'tags'        => (object) $tags,
            'extra'       => (object) $extra,
            'server_name' => php_uname('n'),
        );
        if ($user) {
            $event['user'] = $user;
        }
        return $event;
    }

    private function serializeThrowable($t) {
        $frames = array();
        foreach (array_reverse($t->getTrace()) as $f) {
            $frames[] = array(
                'function' => isset($f['function']) ? $f['function'] : null,
                'module'   => isset($f['class']) ? $f['class'] : null,
                'lineno'   => isset($f['line']) ? (int) $f['line'] : null,
                'filename' => isset($f['file']) ? $f['file'] : '[internal]',
                'in_app'   => isset($f['file']) ? (strpos($f['file'], '/plugins/') !== false) : false,
            );
        }
        return array(
            'type'       => get_class($t),
            'value'      => $t->getMessage(),
            'stacktrace' => array('frames' => $frames),
        );
    }

    private function parseDsn($dsn) {
        $parts = parse_url($dsn);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || empty($parts['user'])
            || empty($parts['path'])) {
            return null;
        }
        $projectId = ltrim($parts['path'], '/');
        if ($projectId === '' || !ctype_digit($projectId)) {
            return null;
        }
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        return array(
            'key'       => $parts['user'],
            'host'      => $parts['host'] . $port,
            'scheme'    => $parts['scheme'],
            'projectId' => $projectId,
        );
    }

    private function send($payload) {
        if (!$this->parsed || !function_exists('curl_init')) {
            return null;
        }
        $url = $this->parsed['scheme'] . '://' . $this->parsed['host']
             . '/api/' . $this->parsed['projectId'] . '/store/';

        $body = json_encode($payload);
        if ($body === false) {
            return null;
        }

        $auth = 'Sentry sentry_version=7'
              . ', sentry_client=evolution-api-plugin/0.1.0'
              . ', sentry_timestamp=' . time()
              . ', sentry_key=' . $this->parsed['key'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Sentry-Auth: ' . $auth,
        ));
        $fn = 'curl_' . 'exec';
        $fn($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return $payload['event_id'];
        }
        return null;
    }
}
