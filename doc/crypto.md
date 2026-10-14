# UPM crypto — verified reference for the browser

This documents exactly how to reproduce the Java/Android encryption in a
browser, using only WebCrypto primitives. It is **verified byte-for-byte**
against `scripts/test.db` (see `tmp/verify_kdf.sh` / `tmp/upm_kdf.py`).

## What the Android app does

From `src/com/u17od/upm/crypto/EncryptionService.java`:

```java
private static final String PBEWithSHA256And256BitAES = "PBEWithSHA256And256BitAES-CBC-BC";
public static final int SALT_LENGTH = 8;
public static final int SALT_GEN_ITER_COUNT = 20;

new PBEParameterSpec(salt, SALT_GEN_ITER_COUNT);          // salt = 8 bytes, 20 iterations
Cipher.getInstance(PBEWithSHA256And256BitAES);            // AES/CBC/PKCS5(=PKCS7) padding
SecretKeyFactory.getInstance(PBEWithSHA256And256BitAES);  // PBEKeySpec(password)
```

That algorithm string expands, in BouncyCastle, to:

| Component | Value |
|---|---|
| Key derivation | **PKCS#12 KDF** (BouncyCastle `PKCS12ParametersGenerator`) |
| Digest | SHA-256 |
| Key length | 256 bits (AES-256) |
| IV length | 128 bits |
| Cipher | AES-CBC with **PKCS#7** padding (Java "PKCS5Padding") |
| Salt | 8 bytes, stored in the file |
| Iterations | **20** |

> Confirmed from BouncyCastle source:
> `super("PBEWithSHA256And256BitAES-CBC-BC", null, true, PKCS12, SHA256, 256, 128);`

## File layout (version 3)

```
offset 0 : "UPM"        3 bytes, ASCII 55 50 4D
offset 3 : version      1 byte, 0x03
offset 4 : salt         8 bytes
offset 12: ciphertext   AES-256-CBC(payload), PKCS#7 padded
```

The **whole payload** is encrypted; there is no per-field encryption. After
decryption it is a stream of flat-packed fields (see the
`upm-database-format` skill):

```
Revision          : one length-prefixed int
DatabaseOptions   : two length-prefixed strings (remoteLocation, authDBEntry)
AccountInformation: zero or more, each 5 length-prefixed strings, read to EOF
```

Flat-pack = `LLLL<UTF-8 bytes>` where `LLLL` is a 4-digit zero-padded decimal
byte length (e.g. `0005hello`). Ints are packed as their decimal string.

## The PKCS#12 KDF (what to implement in TypeScript)

Inputs: `password` (string), `salt` (8 bytes), `iterations = 20`.

1. **Password bytes** — BouncyCastle `PKCS12PasswordToBytes(char[])`:
   - UTF-16 **big-endian** of every code unit, then **two 0x00 bytes**.
   - `"test123"` → `00 74 00 65 00 73 00 74 00 31 00 32 00 33 00 00` (16 bytes).

2. **Derive a block** for a diversifier `idByte`:
   - `u = 32` (SHA-256 digest size), `v = 64` (SHA-256 block size).
   - `D = idByte` repeated 64 times (`idByte = 1` for key, `2` for IV).
   - `S = salt` repeated to fill 64 bytes (8-byte salt → repeated 8×).
   - `P = password bytes` repeated to fill 64 bytes (16 bytes → repeated 4×).
   - `I = S || P`.
   - `A = SHA256(D || I)`.
   - Repeat `A = SHA256(A)` a further `iterations - 1` times (19 more, 20 total).
   - Result = first `u` (32) bytes of `A`.

3. **Key** = block with `idByte = 1`, take all 32 bytes.
   **IV**  = block with `idByte = 2`, take first 16 bytes.

   (A single SHA-256 block yields 32 bytes, so the "increment I" step of the
   PKCS#12 algorithm never comes into play here — one block is enough for both
   the 32-byte key and the 16-byte IV.)

4. Decrypt/encrypt the payload with AES-256-CBC + PKCS#7 padding.

## TypeScript reference implementation

```ts
// sha256 helper: WebCrypto digest
async function sha256(data: Uint8Array): Promise<Uint8Array> {
  return new Uint8Array(await crypto.subtle.digest('SHA-256', data));
}

function utf16beWithNullTerminator(s: string): Uint8Array {
  const codeUnits = new Uint16Array(s.length);
  for (let i = 0; i < s.length; i++) codeUnits[i] = s.charCodeAt(i);
  const bytes = new Uint8Array((s.length + 1) * 2);
  // UTF-16BE: high byte first
  for (let i = 0; i < s.length; i++) {
    bytes[i * 2]     = (codeUnits[i] >> 8) & 0xff;
    bytes[i * 2 + 1] =  codeUnits[i]       & 0xff;
  }
  // last two bytes remain 0x00 0x00
  return bytes;
}

function repeatTo(data: Uint8Array, length: number): Uint8Array {
  const out = new Uint8Array(length);
  for (let i = 0; i < length; i++) out[i] = data[i % data.length];
  return out;
}

const V = 64; // SHA-256 block size

async function pkcs12Block(idByte: number, password: string,
                           salt: Uint8Array, iterations: number): Promise<Uint8Array> {
  const D = new Uint8Array(V).fill(idByte);
  const S = repeatTo(salt, V);
  const P = repeatTo(utf16beWithNullTerminator(password), V);
  const I = new Uint8Array(S.length + P.length);
  I.set(S, 0);
  I.set(P, S.length);

  let A = await sha256(concat(D, I));
  for (let i = 1; i < iterations; i++) {
    A = await sha256(A);
  }
  return A; // 32 bytes
}

async function deriveKeyAndIv(password: string, salt: Uint8Array): Promise<{ key: CryptoKey; iv: Uint8Array }> {
  const ITER = 20;
  const keyBytes = (await pkcs12Block(1, password, salt, ITER)).slice(0, 32);
  const iv        = (await pkcs12Block(2, password, salt, ITER)).slice(0, 16);
  const key = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-CBC' }, false, ['encrypt', 'decrypt']);
  return { key, iv };
}

async function decryptDatabase(fileBytes: Uint8Array, password: string): Promise<Uint8Array> {
  if (fileBytes[0] !== 0x55 || fileBytes[1] !== 0x50 || fileBytes[2] !== 0x4d) {
    throw new Error('Not a UPM database');
  }
  const salt = fileBytes.slice(4, 12);
  const ciphertext = fileBytes.slice(12);
  const { key, iv } = await deriveKeyAndIv(password, salt);
  return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-CBC', iv }, key, ciphertext));
}

async function encryptDatabase(payload: Uint8Array, password: string,
                               salt: Uint8Array): Promise<Uint8Array> {
  const { key, iv } = await deriveKeyAndIv(password, salt);
  const ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-CBC', iv }, key, payload));
  const out = new Uint8Array(12 + ciphertext.length);
  out.set([0x55, 0x50, 0x4d, 0x03], 0); // "UPM" + version 3
  out.set(salt, 4);
  out.set(ciphertext, 12);
  return out;
}
```

Notes:

- WebCrypto's `AES-CBC` `decrypt`/`encrypt` already applies/strips PKCS#7
  padding, matching Java's PKCS5Padding.
- **Wrong password** is detected by the final padding check: WebCrypto throws
  `OperationError` when the padding bytes are invalid — the same signal the Java
  code maps to `InvalidPasswordException`.
- **Saving** reuses the same salt so the file stays decryptable with the same
  master password. A **new** database needs a fresh random 8-byte salt from
  `crypto.getRandomValues(new Uint8Array(8))`.

## Verified result

Decrypting `scripts/test.db` (password `test123`) yields this flat-pack:

| Field | Value |
|---|---|
| revision | `2` |
| dbOptions.remoteLocation | `` (empty) |
| dbOptions.authDBEntry | `` (empty) |
| account 1: name | `test` |
| account 1: userId | `test` |
| account 1: password | `bYxwbP1r` |
| account 1: url | `` (empty) |
| account 1: notes | `test` |

Re-run the check at any time: `bash tmp/verify_kdf.sh`.
