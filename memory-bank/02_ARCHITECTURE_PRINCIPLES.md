# 02 — Architecture Principles

## Pattern: Hexagonal Architecture (Ports & Adapters)

```
┌─────────────────────────────────────────────────────────┐
│                   INBOUND ADAPTERS                       │
│  HTTP Router → Controllers (ReceiveController, etc.)    │
└──────────────────────────┬──────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                  APPLICATION CORE                        │
│  Use Cases: TransmitMessage, ReceiveMessage,            │
│             ProcessReceipt, RegisterPartner             │
│                                                         │
│  Domain:    Message, Partner, Receipt, KeyPair,         │
│             MessageStatus (enum)                        │
└──────────────────────────┬──────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                  OUTBOUND PORTS                          │
│  MessageRepositoryPort, PartnerRepositoryPort,          │
│  CryptoServicePort, QueuePort, LoggerPort               │
└──────────────────────────┬──────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                 OUTBOUND ADAPTERS                        │
│  SqliteMessageRepository, SqlitePartnerRepository,      │
│  JoseCryptoService, DatabaseQueue, FileLogger,          │
│  CurlHttpClient                                         │
└─────────────────────────────────────────────────────────┘
```

## SOLID Application

| Principle | Implementation |
|-----------|---------------|
| SRP | Each use case handles exactly one operation |
| OCP | Adapters can be swapped without modifying core |
| LSP | All port implementations are substitutable |
| ISP | Ports are narrow: `MessageRepositoryPort` has only message ops |
| DIP | Use cases depend on port interfaces, never on adapters |

## Directory Convention

```
src/
  Core/
    Domain/       ← Pure PHP entities, no framework deps
    UseCase/      ← Application services (orchestrators)
  Port/
    Outbound/     ← PHP interfaces defining contracts
  Adapter/
    Inbound/
      Http/       ← Router + Controllers (incl. NodeInfoController)
    Outbound/
      Crypto/     ← JoseCryptoService
      Http/       ← CurlHttpClient
      Logger/     ← FileLogger
      Persistence/← SQLite/MySQL repositories + schema
      Queue/      ← DatabaseQueue
config/           ← bootstrap.php (DI container, exposes nodeId/nodeName/nodeBaseUrl)
public/
  index.php       ← Front controller (all routes registered here)
  .htaccess       ← Apache URL rewriting
  onboarding/     ← QR Onboarding Webapp (HTML5/CSS3/TypeScript)
    index.html    ← SPA shell with <base href="/onboarding/">
    style.css     ← CSS3 dark theme, responsive
    app.js        ← Compiled TypeScript (committed, CDN-free)
    lib/          ← Vendored: qrcode-generator, jsQR
webapp/
  src/app.ts      ← TypeScript source (npm run build → app.js)
  tsconfig.json
bin/              ← CLI scripts (worker, migrate, keygen)
tests/
  Unit/           ← No I/O; uses InMemory doubles
  Integration/    ← Hits real DB (uses :memory: SQLite)
```
