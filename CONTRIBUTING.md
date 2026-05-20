# Contributing

Thanks for considering a contribution!

## Quick guidelines

- **License:** All contributions are accepted under GPL-2.0-or-later.
- **Code style:** Mirror `osTicket/osTicket-plugins`. PHP 7.4+ compatible.
- **No Composer deps in the runtime plugin** — keep it drop-in deployable.
- **No secrets** in commits. Never commit bot tokens, webhook secret tokens, real chat IDs, DSNs, or `.env` files.
- **Tests** for any non-trivial logic in `plugin/lib/`. They must run without booting osTicket (mock anything you need).
- **Docs**: update both `README.md` and `README.es.md` when adding a user-visible option. Longer prose goes in `docs/`.
- **Conventional commits** appreciated (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`).

## Local development

```bash
git clone https://github.com/RenatoAscencio/osticket-telegram-bot.git
cd osticket-telegram-bot/docker
docker compose up -d
# Edit plugin/ files — they're bind-mounted live into osTicket.
```

Run unit tests:

```bash
php tests/run-all.php
```

## PR upstream to osTicket/osTicket-plugins

When v1.0 is stable, the goal is to PR `plugin/` into `osTicket/osTicket-plugins` as a new top-level folder (`telegram-bot/`). Before doing that:

1. `php -l` clean on every file in `plugin/`.
2. All tests pass on PHP 7.4 + 8.1 + 8.3 in CI.
3. The Docker stack boots end-to-end (osTicket installer + plugin install + token validation).
4. At least one production deployment running cleanly for 30+ days.

See `docs/upstream-pr.md`.
