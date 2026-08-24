<?php
declare(strict_types=1);

// Runs on every container start (see Dockerfile CMD). Creates the schema on a
// fresh database, and is safe to re-run on an existing one — it only adds
// what's missing (new columns/tables from later releases) instead of failing.

$driver = getenv('DB_DRIVER') ?: 'sqlite';

if ($driver === 'sqlite') {
    $path = __DIR__ . '/../storage/dev.sqlite';
    $isNew = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($isNew) {
        $pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.sqlite.sql'));
        echo "SQLite database initialized.\n";
        exit(0);
    }

    // Forward migrations for an existing local sqlite file.
    try {
        $pdo->exec('ALTER TABLE orders ADD COLUMN expires_at TEXT DEFAULT NULL');
    } catch (Throwable $e) {
        // column already exists — fine
    }
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='renewals'")->fetch();
    if (!$exists) {
        $pdo->exec(
            "CREATE TABLE renewals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                months INTEGER NOT NULL DEFAULT 12,
                due_date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                utr_number TEXT DEFAULT NULL,
                utr_submitted_at TEXT DEFAULT NULL,
                approved_at TEXT DEFAULT NULL,
                approved_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES admin_users(id)
            )"
        );
        echo "SQLite: renewals table created.\n";
    }
    echo "SQLite database already existed — checked for migrations.\n";
    exit(0);
}

// ---------------------------------------------------------------
// MySQL
// ---------------------------------------------------------------
$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'hosting_panel';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$pdo = null;
for ($i = 0; $i < 30; $i++) {
    try {
        $pdo = new PDO("mysql:host=$host", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (Throwable $e) {
        echo "Waiting for MySQL at $host ... attempt " . ($i + 1) . "\n";
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "Could not connect to MySQL after 30 attempts — giving up.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");

$tables = $pdo->query("SHOW TABLES LIKE 'orders'")->fetchAll();

if (!$tables) {
    // Fresh database — run the full schema. Strip the hardcoded
    // CREATE DATABASE/USE lines since we already created/selected the real one above.
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    $sql = preg_replace('/^CREATE DATABASE.*$/mi', '', $sql);
    $sql = preg_replace('/^USE\s+\S+;\s*$/mi', '', $sql);

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    echo "MySQL schema initialized on `$name`.\n";
    exit(0);
}

// Existing database — apply forward migrations only, never touch existing data.
try {
    $pdo->exec('ALTER TABLE orders ADD COLUMN expires_at DATETIME DEFAULT NULL');
    echo "MySQL: added orders.expires_at.\n";
} catch (Throwable $e) {
    // column already exists — fine
}

$renewalsExist = $pdo->query("SHOW TABLES LIKE 'renewals'")->fetchAll();
if (!$renewalsExist) {
    $pdo->exec(
        "CREATE TABLE renewals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            months TINYINT NOT NULL DEFAULT 12,
            due_date DATE NOT NULL,
            status ENUM('pending','utr_submitted','approved','rejected') NOT NULL DEFAULT 'pending',
            utr_number VARCHAR(50) DEFAULT NULL,
            utr_submitted_at DATETIME DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES admin_users(id)
        ) ENGINE=InnoDB"
    );
    echo "MySQL: renewals table created.\n";
}

echo "MySQL database already existed on `$name` — checked for migrations.\n";
