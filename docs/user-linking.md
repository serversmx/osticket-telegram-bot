# Customer account linking

How customers connect their osTicket account to a Telegram chat so they
receive ticket notifications. Two methods, can run simultaneously.

---

## Method A — Bot deep-link (recommended)

### Flow

1. Customer goes to **My Profile** in the osTicket portal.
2. Sees a **"Link Telegram"** button (added by an osTicket form
   modification — see *Wiring the button* below).
3. Clicks it; opens `https://t.me/<your-bot>?start=<one-shot-token>`.
4. Customer hits **Start** in Telegram.
5. The bot receives `/start <token>`, the plugin's webhook handler:
   - Looks up the token in `<prefix>telegram_link_tokens`.
   - Deletes it (one-shot).
   - Writes `(user_id, chat_id)` into `<prefix>telegram_links`.
   - Replies in Telegram: `✅ Your Telegram is now linked…`.

### Wiring the entry point — `link-telegram.php`

Since v0.1.4 the plugin ships a ready-to-use redirect script:
**`plugin/link-telegram.php.example`**. Copy it to the osTicket public
root, then point your customer-facing UI at that URL.

```bash
cp /path/to/osticket/include/plugins/telegram-bot/link-telegram.php.example \
   /path/to/osticket/link-telegram.php
chown <web-user>:<web-group> /path/to/osticket/link-telegram.php
chmod 644 /path/to/osticket/link-telegram.php
```

What the script does:

1. `require 'client.inc.php'` — bootstraps osTicket and enforces customer auth.
2. Locates the active plugin instance via `PluginManager::allInstalled()`.
3. Calls `$plugin->generateLinkUrl($thisclient->getId())` to mint a one-shot,
   15-min-TTL token.
4. `Http::redirect()` to `https://t.me/<bot>?start=<token>`.

From the customer's point of view this is **a single click**. They never
see the chat_id and don't have to copy-paste anything.

### Surfacing the link in osTicket

osTicket doesn't have a per-user-profile button slot natively, so you have
a few ways to give customers a place to click:

- **Recommended:** add the URL as the `hint` on the manual `telegram_chat_id`
  form field. Customers see it the first time they open their profile.
- Embed it in a help topic, knowledge base article, or canned response.
- Link it from your portal's homepage / landing template.

### Token TTL

Tokens are one-shot and expire 15 minutes after creation by default
(configurable via `link_token_ttl` in plugin config). Expired tokens
prompt the bot to ask the customer for a fresh one.

### Customer-initiated unlink

A linked customer can send `/unlink` to the bot at any time. The plugin
deletes the mapping; future notifications are skipped (chat ID unknown).

### Status

Send `/status` to the bot to see if a chat is linked and which user ID
it's tied to.

---

## Method B — Manual chat_id paste

For installs without a public webhook or with a tightly controlled
admin-driven flow.

### Setup

1. **Admin Panel → Manage → Forms → Contact Information → Add new field**:
   - **Label:** "Telegram chat ID (optional)"
   - **Variable:** `telegram_chat_id` (matches plugin's
     `manual_chat_id_field_variable` config)
   - **Type:** Text
   - **Visible to customer:** Yes (or No, if you want admin-only entry)

2. In plugin config, ensure **"Allow manual chat_id entry"** is on.

### How customers get their chat ID

1. They start a chat with **[@userinfobot](https://t.me/userinfobot)**.
2. The bot replies with their numeric chat ID.
3. They paste it into the field on their osTicket profile.

### Caveats vs. Method A

| Concern | Method A | Method B |
| ------- | -------- | -------- |
| Webhook required | yes | no |
| Verifies customer actually owns the chat | yes (they had to press Start on YOUR bot) | no (anyone can paste any chat_id) |
| Customer effort | one click | two pastes |
| Admin can revoke | yes via `/unlink` | yes by clearing the field |

For customer-facing installs use Method A; for internal-only osTicket installs Method B is fine.

---

## Honoring opt-in

Whichever method you use, the plugin respects the `telegram_opt_in`
custom field (configurable) on the Contact Information form. When that
checkbox is off, **no notification is sent to the customer regardless of
linking method**.

See [the Evolution plugin's opt-in doc](https://github.com/RenatoAscencio/osticket-evolution-api/blob/main/docs/user-opt-in.md) for the form-setup details — same pattern.

---

## Programmatic interfaces

For staff-facing tooling that wants to integrate:

```php
$plugin = ...;  // PluginManager lookup

// Issue a fresh linking token and bot deep-link URL.
$url = $plugin->generateLinkUrl($userId);
// → "https://t.me/MyBot?start=abc123..."

// Verify the bot token (calls getMe). Returns the bot username or null.
$botUsername = $plugin->checkBot();

// Set or refresh the webhook.
$res = $plugin->applyWebhookFromConfig();
```

These are the only public methods the plugin exposes — everything else
is private internals.
