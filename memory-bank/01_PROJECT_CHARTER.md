# 01 — Project Charter: FideX PHP Reference Implementation

## Project Overview

**Name:** FideX PHP Reference Implementation  
**Repository:** `greicodexjm/fidex-php`  
**Protocol:** [FideX Protocol v1.0](https://github.com/GreicodexJM/fidex-protocol)  
**Target Users:** Small pharmacies, regional distributors running cheap hosting

## Problem Statement

Small pharmacies need to participate in the FideX network (secure B2B pharmaceutical document exchange) but cannot afford:
- Dedicated servers for Go/Node.js hubs
- DevOps expertise
- Complex infrastructure

**Solution:** A PHP 8.2 reference node that works on:
- cPanel shared hosting (~$3/month)
- $1 VPS (Hetzner, DigitalOcean)
- Any PHP 8.2 host with cURL + SQLite

## Core Requirements

1. **Protocol Compliance:** Implement FideX AS5 spec — JWE(JWS(payload)), J-MDN receipts, JWKS endpoint
2. **Zero Server Requirements:** SQLite for storage, flat-file queue, cron for worker
3. **Hexagonal Architecture:** Full port/adapter separation for testability
4. **TDD:** All business logic covered by unit tests before implementation
5. **cPanel Compatibility:** `.htaccess` routing, `public/` web root, cron worker

## Success Criteria

- [ ] `make install && make keys && make migrate && make test` all pass
- [ ] Crypto round-trip test passes (sign → encrypt → decrypt → verify)
- [ ] TransmitMessage + ReceiveMessage use case tests all green
- [ ] Node serves JWKS and AS5 config correctly
- [ ] Health endpoint returns 200 when configured properly

## Out of Scope

- Web UI / Admin panel (future phase)
- SFTP transport (future)
- Key rotation (future)
- Rate limiting middleware (future)
