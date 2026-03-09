<p align="center">
  <a href="https://github.com/GreicodexJM/fidex-protocol">
    <img src="https://github.com/GreicodexJM/fidex-protocol/raw/main/fidex-as5-logo.png"
         alt="FideX Protocol" width="480"/>
  </a>
</p>

<p align="center">
  <a href="https://github.com/GreicodexJM/fidex-protocol"><img src="https://img.shields.io/badge/FideX-Protocol%201.0-4f46e5" alt="FideX Protocol 1.0"/></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2%2B-7c3aed" alt="PHP 8.2+"/></a>
  <img src="https://img.shields.io/badge/License-MIT-10b981" alt="MIT"/>
  <a href="https://github.com/GreicodexJM"><img src="https://img.shields.io/badge/by-GreicodexJM-06b6d4" alt="GreicodexJM"/></a>
</p>

# FideX PHP Reference Implementation

A production-ready PHP reference implementation of the [FideX Protocol](https://github.com/GreicodexJM/fidex-protocol) — secure, asynchronous B2B document exchange using JSON Web Encryption (JWE) and JSON Web Signatures (JWS).

**Designed for small pharmacies running on $1 VPS or cPanel shared hosting.**

---

## What is FideX?

FideX is a lightweight, open B2B messaging protocol for the pharmaceutical supply chain. It replaces proprietary EDI/SOAP with modern JSON + JOSE cryptography:

- 📦 **Sign-then-Encrypt**: Every payload is RS256-signed then RSA-OAEP+A256GCM encrypted
- 📬 **Async delivery**: Envelopes are accepted immediately (HTTP 202), processed in background
- 🧾 **J-MDN receipts**: Cryptographic proof of delivery per message
- 🔍 **AS5 discovery**: Partners register via a public JSON config URL
- 🆔 **URN identifiers**: GLN, GS1, or custom URNs identify nodes

---

## Quick Start

### Prerequisites

- PHP 8.2+ with extensions: `openssl`, `pdo_sqlite`, `curl`, `json`
- Composer
- A web server (Apache/Nginx) or just `php -S` for dev

### 1. Install

```bash
git clone https://github.com/GreicodexJM/fidex-protocol.git fidex-php
cd fidex-php
composer install
cp .env.example .env
```

### 2. Configure

Edit `.env`:

```dotenv
FIDEX_NODE_ID=urn:custom:farmacia-san-juan
FIDEX_NODE_NAME=Farmacia San Juan
FIDEX_NODE_BASE_URL=https://yourdomain.com
FIDEX_API_KEY=$(openssl rand -hex 32)
```

### 3. Generate Keys

```bash
make keys
# Generates RSA-4096 key pairs in keys/
```

### 4. Create Database

```bash
make migrate
# Creates storage/fidex.sqlite with all tables
```

### 5. Run

```bash
make serve        # Dev server on :8080
# OR deploy public/ as your web root
```

Then open **http://localhost:8080/onboarding/** for the QR onboarding UI.

---

## API Reference

### Public Endpoints (no auth)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/receive` | Receive encrypted envelope from partner |
| POST | `/api/v1/receipt` | Receive J-MDN receipt from partner |
| GET | `/api/v1/node-info` | Node identity + QR data (used by onboarding UI) |
| GET | `/.well-known/jwks.json` | This node's public keys (JWKS) |
| GET | `/as5/config` | Node discovery configuration |
| GET | `/health` | Health check |
| GET | `/onboarding/` | QR Onboarding Webapp (HTML5/TypeScript) |

### Internal Endpoints (Bearer token / X-API-Key)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/transmit` | Send a document to a partner |
| POST | `/api/v1/partners/register` | Register a new trading partner |

---

## Send a Document

```bash
curl -X POST https://yourdomain.com/api/v1/transmit \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "destination_partner_id": "urn:gln:0614141000012",
    "document_type": "GS1_ORDER_JSON",
    "receipt_webhook": "https://yourdomain.com/api/v1/receipt",
    "payload": {
      "order_id": "PO-2026-001",
      "items": [{"sku": "MED-ABC-100", "qty": 50}]
    }
  }'
```

Response `202 Accepted`:
```json
{
  "message_id": "fdx-a1b2c3d4-...",
  "status": "QUEUED",
  "timestamp": "2026-03-09T10:00:00.000Z"
}
```

---

## Register a Partner

```bash
curl -X POST https://yourdomain.com/api/v1/partners/register \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "as5_config_url": "https://partner.example.com/as5/config"
  }'
```

---

## Queue Worker

The worker processes async jobs (transmit, decrypt, send J-MDN):

```bash
# Run once manually
make worker

# Loop (for VPS with process supervisors)
make worker-loop

# cPanel Cron Job (every minute)
* * * * * /usr/bin/php /home/user/fidex-php/bin/worker.php >> /tmp/fidex.log 2>&1
```

---

## QR Onboarding Webapp

A built-in admin UI at `/onboarding/` for easy partner onboarding — no separate app required.

**Features:**
- 📡 **My Node QR Code** — Auto-generates a scannable QR from your AS5 config URL. Partners scan it to register your node instantly.
- 📷 **Scan Partner QR** — Uses the device camera (WebRTC) to scan a partner's QR code and call `POST /api/v1/partners/register` automatically.
- ⌨ **Manual entry** — Fallback text field for pasting a partner's AS5 config URL.
- ● **Live status** — Shows node health, partner count, and queue depth on load.

**Tech:** HTML5 + CSS3 + strict TypeScript. Zero CDN dependencies — `qrcode-generator` and `jsQR` are vendored in `public/onboarding/lib/`. The compiled `app.js` is committed so cPanel operators need no Node.js.

**Rebuild from TypeScript source** (optional, for modifications):
```bash
make build-webapp   # requires Node.js + npm
```

---

## cPanel Shared Hosting Deployment

1. Upload all files via FileManager or FTP
2. Set **Document Root** to the `public/` directory
3. Create a cron job for the worker (see above)
4. Set `keys/` directory permissions to `750`
5. Set `keys/*.pem` file permissions to `600`
6. Open `https://yourdomain.com/onboarding/` to onboard trading partners

> The `.htaccess` in `public/` handles URL rewriting automatically.

---

## Architecture

```
src/
├── Core/
│   ├── Domain/          # Pure domain entities (Message, Partner, Receipt, KeyPair)
│   └── UseCase/         # Application logic (TransmitMessage, ReceiveMessage, etc.)
├── Port/
│   └── Outbound/        # Interface contracts (ports)
└── Adapter/
    ├── Inbound/Http/    # Router + HTTP controllers
    └── Outbound/
        ├── Crypto/      # JoseCryptoService (web-token/jwt-framework)
        ├── Http/         # CurlHttpClient
        ├── Logger/       # FileLogger
        ├── Persistence/ # SQLite/MySQL repositories + schema
        └── Queue/        # DatabaseQueue (table-backed)

public/
└── onboarding/          # QR Onboarding Webapp (HTML5/CSS3/TypeScript)
    ├── index.html
    ├── style.css
    ├── app.js           # Pre-compiled TypeScript (source: webapp/src/app.ts)
    └── lib/             # Vendored: qrcode-generator, jsQR

webapp/
└── src/app.ts           # TypeScript source — rebuild with: make build-webapp
```

Built on **Hexagonal Architecture** (Ports & Adapters) with full **TDD** coverage.

---

## Testing

```bash
make test          # All tests
make test-unit     # Unit tests only
make test-crypto   # Crypto round-trip tests
make stan          # PHPStan static analysis
```

---

## Security

- RSA-4096 keys (minimum RSA-2048)
- Sign-then-Encrypt: `JWE(JWS(payload))`
- TLS 1.2+ enforced for all outbound HTTP calls
- Idempotency: duplicate `message_id` rejected
- Payload digest verification on receive
- API key required for internal ERP endpoints
- `.env` and `keys/` excluded from version control

---

## Protocol Spec

Full FideX Protocol specification: [github.com/GreicodexJM/fidex-protocol](https://github.com/GreicodexJM/fidex-protocol)

---

## License

MIT © [GreicodexJM](https://github.com/GreicodexJM)

---

<p align="center">
  <a href="https://github.com/GreicodexJM">
    <img src="https://github.com/GreicodexJM.png" width="40" height="40" style="border-radius:50%" alt="GreicodexJM"/>
  </a><br/>
  Built with ❤️ by <a href="https://github.com/GreicodexJM">GreicodexJM</a>
</p>
