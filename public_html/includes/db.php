<?php
/**
 * Database connection — PDO singleton
 * Reads credentials from .db_credentials in project root
 */

declare(strict_types=1);

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $credFile = __DIR__ . '/../../.db_credentials';

    if (!is_file($credFile)) {
        throw new RuntimeException('.db_credentials file not found at: ' . $credFile);
    }

    $credentials = [];
    foreach (file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $credentials[trim($key)] = trim($value);
        }
    }

    $host   = $credentials['DB_HOST'] ?? 'localhost';
    $dbname = $credentials['DB_NAME'] ?? '';
    $user   = $credentials['DB_USER'] ?? '';
    $pass   = $credentials['DB_PASS'] ?? '';

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
