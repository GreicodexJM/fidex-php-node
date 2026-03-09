<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Persistence;

/**
 * Database Connection Factory
 *
 * Creates PDO connections for SQLite (default) or MySQL.
 * Configured via environment variables.
 *
 * SQLite: zero server config, perfect for shared hosting.
 * MySQL:  optional upgrade for higher concurrency.
 */
final class DatabaseConnection
{
    private static ?\PDO $instance = null;

    /**
     * Get the singleton PDO connection.
     * On first call, creates and configures the connection.
     */
    public static function get(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = self::create();
        return self::$instance;
    }

    /**
     * Reset the singleton (for testing with fresh connections).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Inject a custom PDO instance (for testing with in-memory SQLite).
     */
    public static function inject(\PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    private static function create(): \PDO
    {
        $driver = $_ENV['FIDEX_DB_DRIVER'] ?? getenv('FIDEX_DB_DRIVER') ?: 'sqlite';

        return match ($driver) {
            'sqlite' => self::createSqlite(),
            'mysql'  => self::createMysql(),
            default  => throw new \RuntimeException("Unsupported database driver: {$driver}"),
        };
    }

    private static function createSqlite(): \PDO
    {
        $dbPath = $_ENV['FIDEX_DB_PATH'] ?? getenv('FIDEX_DB_PATH') ?: 'storage/fidex.sqlite';

        // Resolve relative path from project root
        if ($dbPath !== ':memory:' && !str_starts_with($dbPath, '/')) {
            $dbPath = dirname(__DIR__, 5) . '/' . $dbPath;
        }

        // Create directory if needed
        if ($dbPath !== ':memory:') {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // SQLite performance pragmas
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        return $pdo;
    }

    private static function createMysql(): \PDO
    {
        $host = $_ENV['FIDEX_DB_HOST'] ?? getenv('FIDEX_DB_HOST') ?: '127.0.0.1';
        $port = $_ENV['FIDEX_DB_PORT'] ?? getenv('FIDEX_DB_PORT') ?: '3306';
        $name = $_ENV['FIDEX_DB_NAME'] ?? getenv('FIDEX_DB_NAME') ?: 'fidex';
        $user = $_ENV['FIDEX_DB_USER'] ?? getenv('FIDEX_DB_USER') ?: 'fidex';
        $pass = $_ENV['FIDEX_DB_PASSWORD'] ?? getenv('FIDEX_DB_PASSWORD') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);

        return $pdo;
    }
}
