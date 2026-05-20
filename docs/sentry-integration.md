# Sentry integration

The plugin can report errors to a [Sentry](https://sentry.io/) project. Integration is **opt-in** and **drop-in**: no Composer required.

## What gets reported

When **Sentry DSN** is set:

| Source | Reported as | Tags |
| ------ | ----------- | ---- |
| Caught exceptions inside plugin signal handlers | `exception` event | `event=ticket.created` / `threadentry.created` / `model.updated`, `class=<ExceptionClass>` |
| Telegram Bot API non-ok responses | `message` event, level `error` | `endpoint=sendMessage`, `status=<HTTP code>` |

When **Capture global PHP errors** is *also* on:

| Source | Reported as | Tags |
| ------ | ----------- | ---- |
| `E_NOTICE` / `E_USER_NOTICE` | `message`, level `info` | — |
| Other PHP errors | `message`, level `error` | — |
| Uncaught exceptions (anywhere in osTicket) | `exception` event | — |
| Fatal errors (via `register_shutdown_function`) | `message`, level `fatal` | — |

The handlers are non-suppressing: osTicket's own error pages still render. Sentry just gets a copy.

## Setup

### 1. Create a Sentry project

In your Sentry org, create a new project of type **PHP**. Sentry will show you a DSN that looks like:

```
https://<32-char-key>@o123456.ingest.sentry.io/4567890
```

Copy that whole string.

### 2. Paste it into the plugin

**Manage → Plugins → Telegram Bot Notifications → Configure → Sentry section**:

- **Sentry DSN:** paste the DSN.
- **Sentry environment:** `production` (or whatever matches your other services).
- **Capture global PHP errors:** start **off**. Turn on once you've confirmed plugin-scoped reporting works.

### 3. Trigger a test event

Easiest way to verify: temporarily set an invalid bot token in plugin config (one digit off), then create a test ticket that should notify an admin chat. The plugin will attempt sendMessage, hit `401: Unauthorized`, and report the error to Sentry. You should see the issue appear in your Sentry project within seconds.

### 4. (Optional) Use the official Sentry SDK

The bundled `SentryReporter.php` covers basic exception + message capture. If you need:

- Breadcrumbs (trail of events leading up to the error)
- Performance/transaction monitoring
- Release tracking with commits
- User feedback prompts

…install the official SDK via Composer:

```bash
cd /path/to/osticket
composer require sentry/sentry
```

Then in `plugin/lib/SentryReporter.php`, replace the body of `captureException()` / `captureMessage()` with calls to `\Sentry\captureException()` / `\Sentry\captureMessage()`. The plugin's public surface (the methods called from `telegram.php`) stays unchanged.

## Privacy notes

- The plugin never sends ticket bodies, customer names, or phone numbers to Sentry by default.
- Tags include only metadata (event name, exception class, HTTP status).
- If you enable global error capture, PHP error messages can include any variable values osTicket happens to be logging — review periodically.

## Troubleshooting

| Symptom | Likely cause |
| ------- | ------------ |
| Plugin saves DSN but no issues appear | Outbound HTTPS blocked, or DSN host wrong. From the server, try `curl -v <sentry-host>` — should connect on 443. |
| Issues appear but with wrong `environment` | Field is free-text; verify exact spelling. Sentry's UI filters are case-sensitive. |
| Sentry "Invalid DSN" toast on save | Format check failed in `config.php::pre_save()`. DSN must match `https?://<key>@<host>/<project_id>` where `<project_id>` is digits. |
