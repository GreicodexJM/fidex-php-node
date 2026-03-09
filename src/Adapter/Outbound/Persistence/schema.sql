-- FideX PHP Reference Implementation — Database Schema
-- Compatible with: SQLite 3.x, MySQL 8+, MariaDB 10.5+
-- Run via: php bin/migrate.php

-- ─────────────────────────────────────────────────────────────────────────────
-- Messages table
-- Stores all inbound and outbound FideX envelopes and their state.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fidex_messages (
    message_id          TEXT        NOT NULL PRIMARY KEY,
    sender_id           TEXT        NOT NULL,
    receiver_id         TEXT        NOT NULL,
    document_type       TEXT        NOT NULL,
    timestamp           TEXT        NOT NULL,
    status              TEXT        NOT NULL DEFAULT 'PENDING',
    direction           TEXT        NOT NULL CHECK(direction IN ('inbound', 'outbound')),
    raw_payload         TEXT        NOT NULL DEFAULT '{}',   -- JSON, outbound only
    encrypted_payload   TEXT        NOT NULL DEFAULT '',
    receipt_webhook     TEXT,
    retry_count         INTEGER     NOT NULL DEFAULT 0,
    error_message       TEXT,
    created_at          TEXT        NOT NULL,
    updated_at          TEXT        NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fidex_messages_status    ON fidex_messages(status);
CREATE INDEX IF NOT EXISTS idx_fidex_messages_direction ON fidex_messages(direction);
CREATE INDEX IF NOT EXISTS idx_fidex_messages_sender    ON fidex_messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_fidex_messages_receiver  ON fidex_messages(receiver_id);
CREATE INDEX IF NOT EXISTS idx_fidex_messages_updated   ON fidex_messages(updated_at);

-- ─────────────────────────────────────────────────────────────────────────────
-- Partners table
-- Stores registered trading partners and their cached JWKS.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fidex_partners (
    partner_id          TEXT        NOT NULL PRIMARY KEY,
    name                TEXT        NOT NULL,
    receive_endpoint    TEXT        NOT NULL,
    receipt_endpoint    TEXT        NOT NULL,
    jwks_url            TEXT        NOT NULL,
    as5_config_url      TEXT        NOT NULL,
    cached_jwks         TEXT,
    jwks_cached_at      TEXT,
    active              INTEGER     NOT NULL DEFAULT 1,
    created_at          TEXT        NOT NULL,
    updated_at          TEXT        NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fidex_partners_active    ON fidex_partners(active);

-- ─────────────────────────────────────────────────────────────────────────────
-- Receipts table
-- Stores J-MDN receipts (inbound and outbound proofs of delivery).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fidex_receipts (
    id                  INTEGER     NOT NULL PRIMARY KEY AUTOINCREMENT,
    original_message_id TEXT        NOT NULL,
    status              TEXT        NOT NULL,
    receiver_id         TEXT        NOT NULL,
    hash_verification   TEXT        NOT NULL,
    timestamp           TEXT        NOT NULL,
    error_log           TEXT,
    signature           TEXT        NOT NULL DEFAULT '',
    created_at          TEXT        NOT NULL,
    FOREIGN KEY(original_message_id) REFERENCES fidex_messages(message_id)
);

CREATE INDEX IF NOT EXISTS idx_fidex_receipts_message   ON fidex_receipts(original_message_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- Queue table
-- Database-backed async job queue (no Redis/RabbitMQ required).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fidex_queue (
    id                  INTEGER     NOT NULL PRIMARY KEY AUTOINCREMENT,
    job_type            TEXT        NOT NULL,
    payload             TEXT        NOT NULL DEFAULT '{}',   -- JSON
    status              TEXT        NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'processing', 'done', 'failed')),
    retry_count         INTEGER     NOT NULL DEFAULT 0,
    error_message       TEXT,
    available_at        TEXT        NOT NULL,
    locked_at           TEXT,
    locked_by           TEXT,
    created_at          TEXT        NOT NULL,
    updated_at          TEXT        NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fidex_queue_status       ON fidex_queue(status);
CREATE INDEX IF NOT EXISTS idx_fidex_queue_available    ON fidex_queue(available_at);
CREATE INDEX IF NOT EXISTS idx_fidex_queue_job_type     ON fidex_queue(job_type);
