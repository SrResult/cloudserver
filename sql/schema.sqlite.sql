-- SQLite version of the schema — for quick local dev/demo only (DB_DRIVER=sqlite).
-- Production should use sql/schema.sql against real MySQL (see README).
PRAGMA foreign_keys = ON;

CREATE TABLE admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    is_verified INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    purpose TEXT NOT NULL DEFAULT 'register',
    otp_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    expires_at TEXT NOT NULL,
    consumed INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_email_purpose ON otp_codes(email, purpose);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    base_price_12mo REAL NOT NULL,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    tenure_months INTEGER NOT NULL,
    base_amount REAL NOT NULL,
    discount_amount REAL NOT NULL DEFAULT 0,
    final_amount REAL NOT NULL,
    utr_number TEXT DEFAULT NULL,
    utr_submitted_at TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'awaiting_payment',
    approved_at TEXT DEFAULT NULL,
    approved_by INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (approved_by) REFERENCES admin_users(id)
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    token_prefix TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    generated_at TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE developer_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    label TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, email)
);

CREATE TABLE token_usage_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    api_token_id INTEGER NOT NULL,
    developer_email TEXT NOT NULL,
    otp_verified INTEGER NOT NULL DEFAULT 0,
    credentials_sent INTEGER NOT NULL DEFAULT 0,
    ip_address TEXT DEFAULT NULL,
    used_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
);

CREATE TABLE order_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL UNIQUE,
    hostname TEXT DEFAULT NULL,
    username TEXT DEFAULT NULL,
    secret_encrypted TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT
);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('brand_name', 'YourBrand Cloud'),
    ('upi_id', 'yourupi@bank'),
    ('qr_image_path', '/assets/img/payment-qr-placeholder.png'),
    ('discount_24mo', '500'),
    ('discount_36mo', '1000'),
    ('token_delay_minutes', '5');

INSERT INTO products (name, category, base_price_12mo, description) VALUES
    ('Starter Shared Hosting', 'hosting', 1499.00, '1 website, 10GB SSD, free SSL'),
    ('Business VPS - 2GB', 'vps', 5999.00, '2 vCPU, 2GB RAM, 50GB SSD'),
    ('.com Domain', 'domain', 899.00, 'Standard .com domain registration'),
    ('Wildcard SSL', 'ssl', 1999.00, 'Wildcard SSL certificate, 1 year');
