# UPM for Nextcloud

A Nextcloud app that opens Universal Password Manager (UPM) databases with
**all decryption performed in the browser** — the server only ever stores and
forwards the opaque encrypted file.

* Architecture & decisions: [`doc/plan.md`](doc/plan.md)
* Build plan: [`doc/implementation-checklist.md`](doc/implementation-checklist.md)
* Crypto reference (verified byte-for-byte): [`doc/crypto.md`](doc/crypto.md)

## Status

Phase 0 — repository & toolchain scaffolding. The project is currently a plain
TypeScript library skeleton (`src/` + `tests/`), built up phase by phase before
any Nextcloud scaffolding is added.

## Development

Requires Node.js ≥ 20. On CachyOS/Arch, set up the toolchain with
`bash scripts/install-cachyos.sh`.

```bash
npm install        # install dev dependencies
npm test           # run the Vitest suite
npm run typecheck  # tsc --noEmit (alias: npx tsc --noEmit)
```

The crypto/format library lives in `src/`; tests live in `tests/`.

## Fixtures

`scripts/test.db` is a known-answer UPM v3 database (password in
`scripts/test.db.password`, `test123`) used by the crypto round-trip tests.
It is decrypted independently by `bash tmp/verify_kdf.sh` as a cross-check.
