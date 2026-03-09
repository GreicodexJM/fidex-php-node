# Progress — FideX PHP Reference Implementation

## Status: ✅ v0.3.0 — COMPLETE

---

## What Works

### Infrastructure
- [x] PHP 8.1+ compatible (PHP 8.2 target for production)
- [x] Composer setup with `web-token/jwt-framework` ^3.4 and `vlucas/phpdotenv` ^5.6
- [x] PHPUnit 10.5 test suite — **57 tests, 163 assertions, all green**
- [x] Makefile: `help`, `install`, `install-webapp`, `keys`, `migrate`, `test`, `serve`, `worker`, `build-webapp`
- [x] `.env.example` with SQLite and MySQL options
- [x] `.gitignore` protecting keys, database, node_modules
- [x] `package.json` + TypeScript devDependency for webapp

### Domain Layer
- [x] `Message` entity (inbound/outbound, state machine)
- [x] `Partner` entity (AS5 discovery, JWKS cache)
- [x] `Receipt` value object (J-MDN)
- [x] `KeyPair` value object (sign/enc)
- [x] `MessageStatus` enum (PENDING → QUEUED → SENT → ACKNOWLEDGED, etc.)

### Use Cases (all TDD tested)
- [x] `TransmitMessage` — sign, encrypt, enqueue outbound message
- [x] `ReceiveMessage` — validate, store, enqueue inbound decryption
- [x] `ProcessReceipt` — verify J-MDN, update message to ACKNOWLEDGED
- [x] `RegisterPartner` — fetch AS5 config, cache JWKS

### Cryptography
- [x] `JoseCryptoService` — sign (RS256), encrypt (RSA-OAEP + A256GCM)
- [x] Full round-trip: sign → encrypt → decrypt → verify
- [x] `pemToJwk()` for JWKS endpoint
- [x] `signReceipt()` / `verifyReceipt()` for J-MDN

### Persistence
- [x] SQLite schema (`fidex_messages`, `fidex_partners`, `fidex_receipts`, `fidex_queue`)
- [x] `SqliteMessageRepository` with idempotency check
- [x] `SqlitePartnerRepository`
- [x] `DatabaseQueue` (table-backed)
- [x] MySQL-compatible schema (optional)

### HTTP Layer
- [x] `Router` — lightweight URL + method routing
- [x] `ReceiveController` — POST `/api/v1/receive`
- [x] `TransmitController` — POST `/api/v1/transmit` (API key protected)
- [x] `ReceiptController` — POST `/api/v1/receipt`
- [x] `RegisterPartnerController` — POST `/api/v1/partners/register`
- [x] `NodeInfoController` — GET `/api/v1/node-info` (public, used by onboarding UI)
- [x] `JwksController` — GET `/.well-known/jwks.json`
- [x] `As5ConfigController` — GET `/as5/config`
- [x] `HealthController` — GET `/health`
- [x] `public/index.php` — entry point
- [x] `public/.htaccess` — Apache URL rewriting

### Queue Worker
- [x] `bin/worker.php` — handles `transmit_message`, `process_inbound`, `send_jmdn`
- [x] `bin/migrate.php` — safe database migrator (**bug fixed**: SQL comment stripping)
- [x] `bin/generate-keys.php` — RSA-4096 key generator

### Developer Experience (v0.3.0)
- [x] `make test-integration` target — runs only `tests/Integration/` testsuite (fast, no crypto)
- [x] `webapp/tsconfig.json` — fixed `"module": "none"` (was `"None"`, invalid case)
- [x] `public/favicon.svg` — hexagon SVG favicon (indigo → cyan gradient, "F" lettermark)
- [x] `<link rel="icon">` added to onboarding HTML head
- [x] `.htaccess` — `favicon.ico` → `favicon.svg` 301 redirect (suppresses browser 404 noise)
- [x] Removed dead `public/onboarding/img/` (~784KB freed)

### Test Coverage (v0.3.0 additions)
- [x] `ProcessReceiptTest.php` — 7 tests (validation, repo lookups, hash check, status transitions)
- [x] `RegisterPartnerTest.php` — 7 tests (URL validation, HTTP failures, duplicate guard, happy path)
- [x] `tests/Integration/MessageFlowTest.php` — 11 tests (SqliteMessageRepo, SqlitePartnerRepo, DatabaseQueue, :memory: SQLite)

### QR Onboarding Webapp (v0.2.0)
- [x] `public/onboarding/index.html` — HTML5 two-panel layout
- [x] `public/onboarding/style.css` — CSS3 dark theme, responsive grid
- [x] `public/onboarding/app.js` — Pre-compiled TypeScript (11KB, committed)
- [x] `public/onboarding/lib/qrcode.js` — Vendored QR code generator (56KB)
- [x] `public/onboarding/lib/jsQR.js` — Vendored QR scanner (257KB)
- [x] `webapp/src/app.ts` — Strict TypeScript source
- [x] `webapp/tsconfig.json` — TS compiler config
- [x] `<base href="/onboarding/">` — Fixes asset paths at both `/onboarding` and `/onboarding/`

### Documentation
- [x] `README.md` — Full docs including webapp section, new endpoints, updated architecture
- [x] `memory-bank/01_PROJECT_CHARTER.md`
- [x] `memory-bank/02_ARCHITECTURE_PRINCIPLES.md`
- [x] `memory-bank/03_AGENTIC_WORKFLOW.md`

---

## Bug Fixes Applied

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| `make migrate` fails with "no such table" | SQL header comments merged with first `CREATE TABLE` statement; `str_starts_with($s, '--')` filtered it out | Strip all `--` comments with `preg_replace` before splitting |
| 404 on CSS/JS assets | `<base>` tag missing; relative paths resolved from `/` not `/onboarding/` | Added `<base href="/onboarding/">` to HTML `<head>` |

---

## Known Issues / Future Work

| Issue | Priority | Notes |
|-------|----------|-------|
| PHPUnit deprecation (1) | Low | PHPUnit 10 → 11 migration, php 8.2 only |
| JWKS rotation | Medium | No key rotation mechanism yet |
| Admin UI beyond onboarding | Low | Message list, partner list |
| Rate limiting | Medium | Add to HTTP middleware layer |
| SFTP transport | Low | Protocol extension |
| Signature verification on receive | Medium | JWKS fetching implemented, verify call TODO |

---

## Test Performance Note

Crypto tests use 1024-bit RSA keys for speed (test-only).
Production code always uses 4096-bit as recommended by FideX spec.
Full test suite runs in ~2 minutes due to RSA operations on PHP 8.1.
On PHP 8.2+ with OpenSSL 3.x the suite runs significantly faster.
