<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = $_ENV['DB_DRIVER'] ?? 'mysql';

    if ($driver === 'sqlite') {
        // Quick local-dev / demo mode — no MySQL server required. Production
        // deployments should use DB_DRIVER=mysql (the default) per the README.
        $path = $_ENV['DB_NAME'] ?? __DIR__ . '/../storage/dev.sqlite';
        $pdo = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? 'hosting_panel';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';

    $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
