#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FideX PHP — Database Migrator
 *
 * Creates all required tables in the SQLite (or MySQL) database.
 * Safe to run multiple times — uses CREATE TABLE IF NOT EXISTS.
 *
 * Usage: php bin/migrate.php
 *        make migrate
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use FideX\Adapter\Outbound\Persistence\DatabaseConnection;

$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

echo "FideX PHP — Running migrations..." . PHP_EOL;

$pdo = DatabaseConnection::get();

$schemaPath = dirname(__DIR__) . '/src/Adapter/Outbound/Persistence/schema.sql';
$sql = file_get_contents($schemaPath);

if ($sql === false) {
    echo "ERROR: Could not read schema file at {$schemaPath}" . PHP_EOL;
    exit(1);
}

// Strip SQL line comments (-- ...) before splitting so header comments
// don't get merged with the first CREATE TABLE statement.
$sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

// Execute each statement separately (SQLite PDO can't run multiple at once)
$statements = array_filter(
    array_map('trim', preg_split('/;/m', $sql)),
    fn (string $s) => !empty($s)
);

$count = 0;
foreach ($statements as $statement) {
    if (empty(trim($statement))) {
        continue;
    }
    try {
        $pdo->exec($statement . ';');
        $count++;
    } catch (\PDOException $e) {
        echo "ERROR on statement: " . PHP_EOL . $statement . PHP_EOL;
        echo "PDO Error: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

echo "✓ Migration complete. {$count} statements executed." . PHP_EOL;
