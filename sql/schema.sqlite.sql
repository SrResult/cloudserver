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
    pricing_type TEXT NOT NULL DEFAULT 'tenure', -- 'tenure' or 'onetime'
    gst_applicable INTEGER NOT NULL DEFAULT 1,
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
    gst_amount REAL NOT NULL DEFAULT 0,
    gst_waived INTEGER NOT NULL DEFAULT 0,
    coupon_code TEXT DEFAULT NULL,
    final_amount REAL NOT NULL,
    utr_number TEXT DEFAULT NULL,
    utr_submitted_at TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'awaiting_payment',
    approved_at TEXT DEFAULT NULL,
    approved_by INTEGER DEFAULT NULL,
    expires_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (approved_by) REFERENCES admin_users(id)
);

-- Renewal invoices — admin sets an amount + due date for a given order's next
-- billing cycle; the client pays it the same UPI/UTR way as a fresh order.
CREATE TABLE renewals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    months INTEGER NOT NULL DEFAULT 12,
    due_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', -- pending, utr_submitted, approved, rejected
    utr_number TEXT DEFAULT NULL,
    utr_submitted_at TEXT DEFAULT NULL,
    approved_at TEXT DEFAULT NULL,
    approved_by INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES admin_users(id)
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    waives_gst INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    usage_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    ('token_delay_minutes', '5'),
    ('gst_percent', '18');

INSERT INTO coupons (code, waives_gst, is_active) VALUES ('NOGST18', 1, 1);

INSERT INTO products (name, category, pricing_type, gst_applicable, base_price_12mo, description) VALUES
    ('Starter Shared Hosting', 'hosting', 'tenure', 1, 2630.00, '1 website, SSD storage, free SSL'),
    ('Business VPS - 2GB', 'vps', 'tenure', 1, 5999.00, '2 vCPU, 2GB RAM, 50GB SSD'),
    ('Business Email VPS Hosting', 'hosting', 'tenure', 1, 10184.00, 'Unlimited storage, 1 website with free SSL, 1 year validity'),
    ('.com Domain', 'domain', 'tenure', 1, 899.00, 'Standard .com domain registration, starts at ₹899/year'),
    ('.in Domain', 'domain', 'tenure', 1, 799.00, '.in domain registration, starts at ₹799/year'),
    ('.org Domain', 'domain', 'tenure', 1, 1100.00, '.org domain registration, starts at ₹1100/year'),
    ('Wildcard SSL', 'ssl', 'tenure', 1, 2000.00, 'Wildcard SSL certificate, secures unlimited subdomains, 1 year'),
    ('Business Email - 5 Mailboxes', 'email', 'onetime', 1, 350.00, '5 business email accounts, 1GB storage per mailbox, 1 year'),
    ('Business Email - 10 Mailboxes', 'email', 'onetime', 1, 550.00, '10 business email accounts, 1GB storage per mailbox, 1 year'),
    ('Business Email - 15 Mailboxes', 'email', 'onetime', 1, 730.00, '15 business email accounts, 1GB storage per mailbox, 1 year'),
    ('Business Email - 20 Mailboxes', 'email', 'onetime', 1, 999.00, '20 business email accounts, 1GB storage per mailbox, 1 year'),
    ('Business Email - 30 Mailboxes', 'email', 'onetime', 1, 1200.00, '30 business email accounts, 1GB storage per mailbox, 1 year'),
    ('Business Email - 40 Mailboxes', 'email', 'onetime', 1, 2500.00, '40 business email accounts, 1GB storage per mailbox, 1 year'),
    ('NGO / Agency Website', 'website', 'onetime', 1, 2000.00, 'Dynamic website, complete functionality, customizable design, 24x7 auto backup'),
    ('E-commerce Website', 'website', 'onetime', 1, 5000.00, 'Complete dynamic e-commerce setup, high speed, payment gateway integrated, full business setup'),
    ('Portfolio Website', 'website', 'onetime', 1, 1500.00, 'Portfolio-only website'),
    ('News Website', 'website', 'onetime', 1, 3000.00, 'Complete dynamic news website with full functionality'),
    ('HR Portal', 'website', 'onetime', 1, 10000.00, 'Complete HR management portal with full functionality'),
    ('Resort Website', 'website', 'onetime', 1, 4000.00, 'Dynamic resort website with booking functionality'),
    ('Hotel & Restaurant Management', 'website', 'onetime', 1, 5000.00, 'Dynamic hotel and restaurant management system, fully coded');
