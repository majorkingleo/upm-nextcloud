---
name: upm-browser-testing
description: 'Test the UPM Nextcloud web app and its WebDAV/blob sync end to end. USE FOR: verifying browser-side decrypt/encrypt round-trips against scripts/test.db; seeding/inspecting a WebDAV or Nextcloud remote database; reproducing sync bugs (401, wrong revision, "sync required", cleartext blocked, merge conflicts); checking the three-way merge matrix in the browser. DO NOT USE FOR: pure crypto/format questions (use upm-database-format), Android emulator work, or build errors. INVOKES: terminal commands (python3 helpers in scripts/), Playwright/browser tools for UI, Vitest for unit tests.'
---

# UPM browser testing

Drive the UPM Nextcloud web app and its sync backends from the command line so
sync behaviour is verified against real bytes, not eyeballed screenshots.

## When to use

- Verifying a crypto/format change end to end (decrypt `scripts/test.db`).
- Reproducing sync flows: unlock, edit an account, trigger sync, verify the
  remote bytes.
- Seeding or inspecting the remote database ("what revision is up there?").
- Diagnosing sync failures: 401, 403/404, wrong revision, merge conflicts.

## Fixtures and helpers

| Asset | What it is |
|---|---|
| `scripts/test.db` | Known-answer v3 database (master password `test123`), 1 account (`test`/`test`/`bYxwbP1r`), revision 2 |
| `scripts/test.db.password` | Master password for `test.db` |
| `scripts/webdav-test.py` | Drives the remote side: `check`, `status`, `push`, `pull`, `delete`, `reset` |
| `scripts/webdav-test-server.py` | Local WebDAV-ish server (GET/PUT/DELETE/PROPFIND + optional Basic auth, request log) |
| `tmp/verify_kdf.sh` | Byte-for-byte KDF check against `scripts/test.db` |

Run `python3 scripts/<script>.py --help` for the full flag list.

## Procedure: verify crypto round-trip (no server needed)

```
bash tmp/verify_kdf.sh            # decrypt scripts/test.db, print fields
```

## Procedure: verify sync end to end

1. Start the throwaway WebDAV server:
   ```
   python3 scripts/webdav-test-server.py --help
   ```
2. Seed / inspect the remote:
   ```
   python3 scripts/webdav-test.py check
   python3 scripts/webdav-test.py status
   python3 scripts/webdav-test.py push      # seed remote from scripts/test.db
   python3 scripts/webdav-test.py delete    # make remote absent (404)
   ```
3. Drive the web app (Playwright or browser tools), confirm the unlocked state,
   then trigger sync and compare hashes:
   ```
   python3 scripts/webdav-test.py status
   ```
   Success criterion: after an upload the remote db md5 equals the app's saved
   db md5; a no-op sync leaves the remote untouched.

## Merge matrix (from docs/database-conflict-merge-plan.md)

Seed base/local/remote states with the helpers and confirm the account list:

| # | Base | Local | Remote | Expected |
|---|---|---|---|---|
| 1 | A | A | A+acc | remote changed only → download |
| 2 | A | A+acc | A | local changed only → upload |
| 3 | A | A+acc1 | A+acc2 | merge → both present |
| 4 | A | A+edit | A | upload the edit |
| 5 | A | A+edit | A+edit2 | conflict → `acc (remote)` copy |
| 6 | A | A-del | A | deletion uploads |
| 7 | A | A+edit | A-del | delete-vs-edit → keep edit |
| 8 | (none) | A+acc1 | A+acc2 | LWW fallback + conflict backup |

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| WebCrypto throws on decrypt | Wrong master password, or a corrupt/truncated file |
| `OperationError` on decrypt | AES-CBC padding failed — the equivalent of `InvalidPasswordException` |
| Remote hash never matches local | Local revision must be greater than remote; any edit bumps it |
| 401 on upload | Remote credentials don't match; Nextcloud + 2FA needs an app password |
| `sync required` blocks edits | A remote URL is set but no sync ran; break the deadlock by clearing the sync config |
| Cleartext blocked | Browsers block WebCrypto on insecure origins; test over https or localhost |

## Notes

- Only edit accounts through the UI; there is no CLI writer for the encrypted
  format (the format skill explains the flat-pack if you need one for tests).
- Don't commit secrets: `scripts/*.password` are test-only.
- `scripts/webdav-test.py` and `webdav-test-server.py` are the source of truth
  for exact subcommands — prefer `--help` over guessing flags.
