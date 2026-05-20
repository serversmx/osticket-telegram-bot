# Upstream PR plan — `osTicket/osTicket-plugins`

This document captures the plan to contribute the plugin back to the official [osTicket plugins repository](https://github.com/osTicket/osTicket-plugins).

## Why a separate repo first

`osTicket-plugins` is a monorepo where each top-level folder is a plugin. Doing all the early iteration there would be noisy. We iterate on this dedicated repo (with Docker stack, CI, multilingual docs, deploy script, Sentry, screenshots) until v1.0.0, then propose `plugin/` as a new folder in upstream.

## Stability gate

Before opening the upstream PR, all of the following must be true:

- [ ] At least one production deployment running cleanly for ≥ 30 days.
- [ ] No open `bug:` issues in this repo.
- [ ] `php -l` clean for every file in `plugin/`.
- [ ] `tests/` suite green on PHP 7.4 + 8.1 + 8.3.
- [ ] CI workflow (`.github/workflows/ci.yml`) green on the last 5 commits to `main`.
- [ ] Plugin tested against osTicket 1.17 and 1.18.

## What ships upstream vs stays here

| Lives in upstream PR | Stays in this repo only |
| -------------------- | ----------------------- |
| `plugin/plugin.php` | `docker/` |
| `plugin/config.php` | `scripts/` |
| `plugin/telegram.php` | `tests/` |
| `plugin/lib/*.php` | `docs/` (most of) |
| A condensed `README.md` (English only) | `README.es.md`, `INSTALL.md`, `docs/deploy-production.md` |
| `LICENSE` (GPL-2.0) header in each file | |

In other words: only the runtime-relevant files go upstream. Project tooling, language localization, server-specific deploy scripts, and Docker harness stay here.

## PR steps (when ready)

1. Fork [`osTicket/osTicket-plugins`](https://github.com/osTicket/osTicket-plugins) under your account.
2. Branch: `add-evolution-api`.
3. Copy `plugin/` to `telegram-bot/` at the root of the fork.
4. Add a short top-level `telegram-bot/README.md` (use the condensed version under `docs/upstream-readme.md` — to be created).
5. Open a PR referencing this repo for context (architecture, tests, demos).
6. Be ready for review feedback. The maintainers usually ask for:
   - Verifying compatibility with the supported osTicket versions.
   - Removing third-party-service-specific terminology from user-facing strings (probably fine here — Telegram is the service, the plugin is named after it explicitly).
   - Squashing the PR to a single commit.

## Forking osTicket itself

The user's brief mentioned possibly forking osTicket itself. That's **not** needed for this plugin — the plugin uses only signals and DB APIs that already exist in osTicket core. A fork would only be necessary if we needed to add a new signal that osTicket doesn't emit yet (e.g. `ticket.transferred`).

If a fork ever becomes necessary, the workflow is:

1. Fork `osTicket/osTicket` under your account.
2. Branch off `develop` (not `master` — `master` is the latest release tag).
3. Add the missing signal where appropriate.
4. PR back upstream.
5. Until merged, this plugin can conditionally `Signal::connect` to it: `if (defined('OST_SIGNAL_TICKET_TRANSFERRED')) { ... }`.
