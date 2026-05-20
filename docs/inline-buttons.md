# Inline buttons

Every outbound message can carry an **inline keyboard** — clickable
buttons that appear directly under the message in Telegram. Customers
and admins tap them to jump to the relevant ticket page.

---

## What you get out of the box

Defaults set by `config.php`:

| Audience | Buttons | Notes |
| -------- | ------- | ----- |
| Customer | 🎟 View ticket | The button URL points at `<base_url>/scp/tickets.php?id=N`. The customer will get redirected to login first if they're not signed in. |
| Admin    | 🎟 View ticket · 💬 Reply | "Reply" appends `#reply` so the page scrolls to the reply form. |

Toggle the whole keyboard off by turning **"Show View ticket button"** off (the row collapses entirely when no buttons remain).

---

## Configuration

In **Manage → Plugins → Telegram Bot Notifications → Configure**, the
**🔘 Inline buttons** section has:

- **Show "View ticket" button** — on by default.
- **"View ticket" button label** — defaults to `🎟 View ticket`. Emojis encouraged.
- **Show "Reply" button (admins only)** — on by default.
- **"Reply" button label** — defaults to `💬 Reply`.

Customer messages never show the "Reply" button (it points at the staff-side reply form they don't have access to).

---

## Constraints

Telegram enforces these — `TgInlineKeyboard` enforces them locally too:

- Maximum **8 buttons per row**.
- Maximum **100 buttons total** per keyboard.
- URL buttons must use `http(s)://` or `tg://` schemes.
- Callback-data buttons: payload max **64 bytes** after UTF-8 encoding.

If you exceed any of these, the extra buttons are silently dropped (the keyboard never sends an oversized markup to Telegram).

---

## Extending — adding more buttons

The current release ships URL buttons only. If you fork to add callback
buttons (for actions like "Close ticket from Telegram"), the wire-up
points are:

### 1. Build the keyboard

In `telegram.php::buildKeyboard()`:

```php
$kb->addRow()
   ->callbackButton('Close ticket', 'evt:close:' . $tid)
   ->callbackButton('Snooze 1h',   'evt:snooze:' . $tid . ':3600');
```

Keep callback_data under 64 bytes. A common pattern is short prefixes
(`evt:`, `usr:`) followed by the action and entity ID.

### 2. Handle the callback

Extend `processUpdate()` in `telegram.php` to inspect
`$update['callback_query']` and route based on the `data` field.

```php
if (isset($update['callback_query'])) {
    $cb   = $update['callback_query'];
    $data = $cb['data'];
    // ... auth: verify the chat_id is linked to a staff member ...
    // ... dispatch action ...
    $this->api()->answerCallbackQuery($cb['id'], 'Done.');
}
```

The plugin already exposes
`TelegramBotClient::answerCallbackQuery()` for the response.

### 3. Auth + safety

Callback buttons are a **WRITE surface**: anyone with the chat ID gets
the buttons, and tapping them is a privileged action if you wire it to
staff operations. Always verify the chat belongs to a Staff user (look
up `<prefix>staff` or maintain a separate staff-link table) before
executing.

We deliberately ship without callback actions in v0.1 because of this
auth complexity. Plan to add an auth model in a future release.
