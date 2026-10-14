# UPM for Nextcloud

A Nextcloud app that opens Universal Password Manager (UPM) databases with
**all decryption performed in the browser** — the server only ever stores and
forwards the opaque encrypted file.

* Architecture & decisions: [`doc/plan.md`](doc/plan.md)
* Build plan: [`doc/implementation-checklist.md`](doc/implementation-checklist.md)
* Crypto reference (verified byte-for-byte): [`doc/crypto.md`](doc/crypto.md)

## Status

Phase 1 — crypto layer (PKCS#12 KDF + AES-256-CBC), verified against
`scripts/test.db`. The project is a plain TypeScript library (`src/` +
`tests/`), built up phase by phase before any Nextcloud scaffolding is added.

## Development

Requires Node.js ≥ 20. On CachyOS/Arch, set up the toolchain with
`bash scripts/install-cachyos.sh`.

```bash
npm install        # install dev dependencies
npm test           # run the Vitest suite
npm run typecheck  # tsc --noEmit (alias: npx tsc --noEmit)
```

The crypto/format library lives in `src/`; tests live in `tests/`.

## Fixtures & crypto verification

`scripts/test.db` is a known-answer UPM v3 database (master password `test123`,
read from `scripts/test.db.password`) used by the crypto round-trip tests.

The KDF/AES implementation is checked against the verified vectors in
`doc/crypto.md` (key `4fe00896…`, IV `3c61f5bc…`). An independent cross-check
(pure Python + `openssl`, kept out of git under `tmp/`) recomputes the same
values:

```bash
bash tmp/verify_kdf.sh
```

For the bundled fixture it prints:

```
salt: ee95963f61e72735
key: 4fe00896368c69f9e3f3a79d3071dd8d09b5d582acec8e56742b0f4352728292
iv:  3c61f5bce260900a03de0f9e1083ab97
plaintext (53 bytes): 3030303132…000474657374
```
