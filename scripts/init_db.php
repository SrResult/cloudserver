<?php
declare(strict_types=1);

// Runs on every container start (see Dockerfile CMD). Creates the schema on a
// fresh database, and is safe to re-run on an existing one — it only adds
// what's missing (new columns/tables from later releases) instead of failing.

$driver = getenv('DB_DRIVER') ?: 'sqlite';

// ---------------------------------------------------------------
// Product / coupon catalog — kept here (not only in sql/schema.sql) so it's
// re-applied idempotently on every boot, on both fresh and existing
// databases. Editing a price/description here and redeploying updates the
// live catalog without touching any past order (orders store their own
// amount snapshot at purchase time, never a live reference to this table).
// ---------------------------------------------------------------
const PRODUCT_CATALOG = [
    ['name' => 'Starter Shared Hosting', 'category' => 'hosting', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 2630.00, 'description' => '1 website, SSD storage, free SSL'],
    ['name' => 'Business VPS - 2GB', 'category' => 'vps', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 5999.00, 'description' => '2 vCPU, 2GB RAM, 50GB SSD'],
    ['name' => 'Business Email VPS Hosting', 'category' => 'hosting', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 10184.00, 'description' => 'Unlimited storage, 1 website with free SSL, 1 year validity'],
    ['name' => '.com Domain', 'category' => 'domain', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 899.00, 'description' => 'Standard .com domain registration, starts at ₹899/year'],
    ['name' => '.in Domain', 'category' => 'domain', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 799.00, 'description' => '.in domain registration, starts at ₹799/year'],
    ['name' => '.org Domain', 'category' => 'domain', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 1100.00, 'description' => '.org domain registration, starts at ₹1100/year'],
    ['name' => 'Wildcard SSL', 'category' => 'ssl', 'pricing_type' => 'tenure', 'gst_applicable' => 1, 'price' => 2000.00, 'description' => 'Wildcard SSL certificate, secures unlimited subdomains, 1 year'],
    ['name' => 'Business Email - 5 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 350.00, 'description' => '5 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'Business Email - 10 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 550.00, 'description' => '10 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'Business Email - 15 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 730.00, 'description' => '15 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'Business Email - 20 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 999.00, 'description' => '20 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'Business Email - 30 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 1200.00, 'description' => '30 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'Business Email - 40 Mailboxes', 'category' => 'email', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 2500.00, 'description' => '40 business email accounts, 1GB storage per mailbox, 1 year'],
    ['name' => 'NGO / Agency Website', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 2000.00, 'description' => 'Dynamic website, complete functionality, customizable design, 24x7 auto backup'],
    ['name' => 'E-commerce Website', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 5000.00, 'description' => 'Complete dynamic e-commerce setup, high speed, payment gateway integrated, full business setup'],
    ['name' => 'Portfolio Website', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 1500.00, 'description' => 'Portfolio-only website'],
    ['name' => 'News Website', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 3000.00, 'description' => 'Complete dynamic news website with full functionality'],
    ['name' => 'HR Portal', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 10000.00, 'description' => 'Complete HR management portal with full functionality'],
    ['name' => 'Resort Website', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 4000.00, 'description' => 'Dynamic resort website with booking functionality'],
    ['name' => 'Hotel & Restaurant Management', 'category' => 'website', 'pricing_type' => 'onetime', 'gst_applicable' => 1, 'price' => 5000.00, 'description' => 'Dynamic hotel and restaurant management system, fully coded'],
];

/**
 * Insert-or-update each catalog product by name, create the coupons table's
 * sample coupon if missing, and make sure the gst_percent setting exists.
 * Safe to call repeatedly — never touches products not in PRODUCT_CATALOG
 * (so an admin's own custom-added products are left alone).
 */
function apply_catalog(PDO $pdo): void
{
    foreach (PRODUCT_CATALOG as $item) {
        $check = $pdo->prepare('SELECT id FROM products WHERE name = ?');
        $check->execute([$item['name']]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            $pdo->prepare(
                'UPDATE products SET category = ?, pricing_type = ?, gst_applicable = ?, base_price_12mo = ?, description = ? WHERE id = ?'
            )->execute([$item['category'], $item['pricing_type'], $item['gst_applicable'], $item['price'], $item['description'], $existingId]);
        } else {
            $pdo->prepare(
                'INSERT INTO products (name, category, pricing_type, gst_applicable, base_price_12mo, description, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([$item['name'], $item['category'], $item['pricing_type'], $item['gst_applicable'], $item['price'], $item['description']]);
        }
    }

    $couponCheck = $pdo->prepare('SELECT id FROM coupons WHERE code = ?');
    $couponCheck->execute(['NOGST18']);
    if (!$couponCheck->fetchColumn()) {
        $pdo->prepare('INSERT INTO coupons (code, waives_gst, is_active) VALUES (?, 1, 1)')->execute(['NOGST18']);
    }

    $gstCheck = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gst_percent'");
    $gstCheck->execute();
    if ($gstCheck->fetchColumn() === false) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('gst_percent', '18')")->execute();
    }
}

if ($driver === 'sqlite') {
    $path = __DIR__ . '/../storage/dev.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Don't trust file_exists() alone — a stale/empty dev.sqlite can end up baked
    // into the Docker image (e.g. accidentally committed to the repo). Check for
    // an actual table instead, so a hollow file still gets the schema applied.
    $hasSchema = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_users'")->fetch();

    if (!$hasSchema) {
        $pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.sqlite.sql'));
        echo "SQLite database initialized.\n";
        apply_catalog($pdo);
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

    // GST / coupons / catalog migrations
    foreach (
        [
            'ALTER TABLE products ADD COLUMN pricing_type TEXT NOT NULL DEFAULT \'tenure\'',
            'ALTER TABLE products ADD COLUMN gst_applicable INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE orders ADD COLUMN gst_amount REAL NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN gst_waived INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN coupon_code TEXT DEFAULT NULL',
        ] as $alter
    ) {
        try {
            $pdo->exec($alter);
        } catch (Throwable $e) {
            // column already exists — fine
        }
    }
    $couponsTableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='coupons'")->fetch();
    if (!$couponsTableExists) {
        $pdo->exec(
            "CREATE TABLE coupons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                waives_gst INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                usage_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );
        echo "SQLite: coupons table created.\n";
    }

    apply_catalog($pdo);
    echo "SQLite database already existed — checked for migrations and refreshed catalog.\n";
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

/**
 * Strips whole-line SQL "--" comments before splitting a script on ';' —
 * without this, a comment-only chunk between two statements can survive the
 * split as a non-empty (but content-free) "statement" and blow up with a
 * 1064 syntax error when exec()'d.
 */
function strip_sql_comment_lines(string $sql): string
{
    $lines = explode("\n", $sql);
    $kept = array_filter($lines, function ($line) {
        return trim($line) !== '' && strpos(ltrim($line), '--') !== 0;
    });
    return implode("\n", $kept);
}

$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$hasOrders = in_array('orders', $allTables, true);

if (empty($allTables)) {
    // Genuinely empty database — run the full schema in one go. Strip the
    // hardcoded CREATE DATABASE/USE lines since we already created/selected
    // the real one above.
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    $sql = preg_replace('/^CREATE DATABASE.*$/mi', '', $sql);
    $sql = preg_replace('/^USE\s+\S+;\s*$/mi', '', $sql);
    $sql = strip_sql_comment_lines($sql);

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    echo "MySQL schema initialized on `$name`.\n";
    apply_catalog($pdo);
    exit(0);
}

if (!$hasOrders) {
    // Database has some leftover tables from an earlier partial setup, but
    // not the full schema — replay the schema statements idempotently,
    // skipping only the ones that fail because that object already exists.
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    $sql = preg_replace('/^CREATE DATABASE.*$/mi', '', $sql);
    $sql = preg_replace('/^USE\s+\S+;\s*$/mi', '', $sql);
    $sql = strip_sql_comment_lines($sql);

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // 1050 table exists, 1060 duplicate column, 1061/1091 duplicate/unknown key,
            // 1062 duplicate seed row — safe to skip, anything else is a real problem
            // and should still fail loudly.
            if (!preg_match('/(1050|1060|1061|1062|1091)/', $e->getMessage())) {
                throw $e;
            }
        }
    }
    echo "MySQL schema reconciled on `$name` (some tables already existed).\n";
    apply_catalog($pdo);
    exit(0);
}

// Existing, already-initialized database — apply forward migrations only, never touch existing data.
foreach (
    [
        'ALTER TABLE orders ADD COLUMN expires_at DATETIME DEFAULT NULL',
        'ALTER TABLE products MODIFY COLUMN category VARCHAR(30) NOT NULL',
        "ALTER TABLE products ADD COLUMN pricing_type ENUM('tenure','onetime') NOT NULL DEFAULT 'tenure'",
        'ALTER TABLE products ADD COLUMN gst_applicable TINYINT(1) NOT NULL DEFAULT 1',
        'ALTER TABLE orders ADD COLUMN gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0',
        'ALTER TABLE orders ADD COLUMN gst_waived TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(50) DEFAULT NULL',
    ] as $alter
) {
    try {
        $pdo->exec($alter);
    } catch (Throwable $e) {
        // column already exists / already correct type — fine
    }
}
echo "MySQL: applied forward column migrations.\n";

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

$couponsExist = $pdo->query("SHOW TABLES LIKE 'coupons'")->fetchAll();
if (!$couponsExist) {
    $pdo->exec(
        "CREATE TABLE coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            waives_gst TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            usage_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    echo "MySQL: coupons table created.\n";
}

apply_catalog($pdo);
echo "MySQL database already existed on `$name` — checked for migrations and refreshed catalog.\n";
