-- White-Label Hosting Panel — Database Schema
-- Run this once against an empty MySQL database.

CREATE DATABASE IF NOT EXISTS hosting_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hosting_panel;

-- ---------------------------------------------------------------
-- Admin users (separate from client users)
-- ---------------------------------------------------------------
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Clients
-- ---------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- OTP codes (used for both registration and developer-email verification)
-- ---------------------------------------------------------------
CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    purpose ENUM('register','developer_access','login_2fa') NOT NULL DEFAULT 'register',
    otp_hash VARCHAR(255) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    expires_at DATETIME NOT NULL,
    consumed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_purpose (email, purpose)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Products / services offered
-- ---------------------------------------------------------------
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(30) NOT NULL, -- hosting, vps, domain, ssl, email, website, or any admin-defined category
    pricing_type ENUM('tenure','onetime') NOT NULL DEFAULT 'tenure', -- tenure = 12/24/36mo with flat discount; onetime = single fixed price
    gst_applicable TINYINT(1) NOT NULL DEFAULT 1, -- 18% GST (see settings.gst_percent) applied unless a GST-waiver coupon is used
    base_price_12mo DECIMAL(10,2) NOT NULL, -- for pricing_type=onetime, this is simply "the price"
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    tenure_months TINYINT NOT NULL, -- 12, 24, 36, or 0 for a one-time (non-tenure) product
    base_amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    gst_waived TINYINT(1) NOT NULL DEFAULT 0,
    coupon_code VARCHAR(50) DEFAULT NULL,
    final_amount DECIMAL(10,2) NOT NULL,
    utr_number VARCHAR(50) DEFAULT NULL,
    utr_submitted_at DATETIME DEFAULT NULL,
    status ENUM(
        'awaiting_payment',
        'pending_utr_verification',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'awaiting_payment',
    approved_at DATETIME DEFAULT NULL,
    approved_by INT DEFAULT NULL, -- admin_users.id
    expires_at DATETIME DEFAULT NULL, -- when the current paid period ends; set on approval, extended on renewal approval
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (approved_by) REFERENCES admin_users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Renewal invoices — admin sets an amount + due date for a given order's
-- next billing cycle; the client pays it the same UPI/UTR way as a fresh order.
-- ---------------------------------------------------------------
CREATE TABLE renewals (
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
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Coupon codes — currently only used to waive GST on an order when applied
-- at checkout. Managed entirely from /admin/pricing.
-- ---------------------------------------------------------------
CREATE TABLE coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    waives_gst TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    usage_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- API tokens issued 5 minutes after admin approval (via cron)
-- ---------------------------------------------------------------
CREATE TABLE api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL, -- sha256 of the raw token; raw token shown once, never stored
    token_prefix VARCHAR(12) NOT NULL, -- short prefix shown in UI for identification, e.g. "hp_ab12"
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    generated_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Whitelisted developer emails a client can hand their API key access to
-- ---------------------------------------------------------------
CREATE TABLE developer_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    label VARCHAR(100) DEFAULT NULL, -- e.g. "Freelancer - Ravi"
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_email (user_id, email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Log every time an API token is used to release credentials
-- ---------------------------------------------------------------
CREATE TABLE token_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_token_id INT NOT NULL,
    developer_email VARCHAR(150) NOT NULL,
    otp_verified TINYINT(1) NOT NULL DEFAULT 0,
    credentials_sent TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Actual provisioned service credentials, entered by admin after manual
-- setup on the real hosting/VPS/domain provider. Encrypted at rest by the
-- app layer (see includes/crypto.php) — never store hosting passwords in
-- plaintext, even here.
-- ---------------------------------------------------------------
CREATE TABLE order_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    hostname VARCHAR(150) DEFAULT NULL,
    username VARCHAR(150) DEFAULT NULL,
    secret_encrypted TEXT DEFAULT NULL, -- encrypted password/API secret, not plaintext
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Simple key/value settings (brand name, UPI id, QR image path, etc.)
-- ---------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('brand_name', 'YourBrand Cloud'),
    ('upi_id', 'yourupi@bank'),
    ('qr_image_path', '/assets/img/payment-qr-placeholder.png'),
    ('discount_24mo', '500'),
    ('discount_36mo', '1000'),
    ('token_delay_minutes', '5'),
    ('gst_percent', '18');

-- Seed a default admin — CHANGE THIS PASSWORD IMMEDIATELY (see README)
-- password placeholder hash is generated by scripts/make_admin.php, not inserted here.

-- Sample coupon — waives GST when applied at checkout. Manage more from /admin/pricing.
INSERT INTO coupons (code, waives_gst, is_active) VALUES ('NOGST18', 1, 1);

-- Product catalog (tenure = 12/24/36mo with flat discount, onetime = single fixed price)
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
