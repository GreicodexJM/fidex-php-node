# 03 — Agentic Workflow (SPARC Framework)

## Development Cycle Applied

### Phase 1 — Specification (TDD RED)
All tests written before implementation:
- `JoseCryptoServiceTest` — 13 crypto round-trip tests
- `TransmitMessageTest` — 8 use case tests
- `ReceiveMessageTest` — 10 use case tests
- `ProcessReceiptTest` — 6 use case tests
- `RegisterPartnerTest` — 5 use case tests

### Phase 2 — Implementation (TDD GREEN)
Minimum code to pass each test written in order:
1. Domain entities (`Message`, `Partner`, `Receipt`, `KeyPair`)
2. Port interfaces
3. In-memory test doubles
4. Use cases
5. Adapters (JoseCryptoService, Repositories, Queue)
6. HTTP layer (Router, Controllers)

### Phase 3 — Refactor (TDD REFACTOR)
- Extracted `DatabaseConnection` singleton
- Consolidated routing into single `Router` class
- Ensured all `readonly` constructor properties used

### Phase 4 — QR Onboarding Webapp
New feature added without breaking existing tests:
1. `NodeInfoController` — public `GET /api/v1/node-info` endpoint
2. `webapp/src/app.ts` — strict TypeScript SPA (no framework)
3. Compiled to `public/onboarding/app.js` via `tsc`
4. Vendored `qrcode-generator` + `jsQR` libs (no CDN)
5. `<base href="/onboarding/">` for correct asset path resolution

### Phase 5 — Bug Fixes
Two bugs found and fixed during user testing:

**Bug 1: `make migrate` fails**
- Root cause: `preg_split('/;(\s*\n)/m', $sql)` merged SQL header comments with first `CREATE TABLE`, then `str_starts_with($s, '--')` filter discarded the entire statement.
- Fix: `preg_replace('/--[^\n]*/', '', $sql)` strips comments before splitting; simplified split to `preg_split('/;/m', $sql)`.

**Bug 2: 404 on static assets at `/onboarding`**
- Root cause: `<base>` tag missing; relative paths (`style.css`, `app.js`, `lib/...`) resolved to `/` root when URL was `/onboarding` without trailing slash.
- Fix: Added `<base href="/onboarding/">` to `<head>`.

## Test Doubles Strategy

All unit tests use pure in-memory doubles:
- `InMemoryMessageRepository`
- `InMemoryPartnerRepository`
- `InMemoryQueue`

PHPUnit `createMock()` used only for `LoggerPort` (side-effect only).

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| SQLite as default | Zero server requirements for cPanel hosting |
| Table-based queue | No Redis/Beanstalk needed; cron-triggered |
| PEM file keys | Simple file system key management |
| Dotenv config | cPanel-friendly, no YAML/XML required |
| `public/` web root | Standard cPanel directory structure |
| `.htaccess` routing | Apache-native, no Nginx needed |

## cPanel Deployment Checklist

1. Upload repository files via cPanel File Manager or FTP
2. Set Document Root to `public/`
3. Run `php bin/generate-keys.php` via cPanel Terminal
4. Run `php bin/migrate.php` via cPanel Terminal
5. Add cron job: `* * * * * php /path/to/bin/worker.php`
6. Set `keys/` chmod 750, `keys/*.pem` chmod 600
7. Copy `.env.example` → `.env`, fill in values
