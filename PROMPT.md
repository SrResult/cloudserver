# Improved Build Prompt — White-Label Hosting & Domain Billing Portal

Use this as a reusable spec (for Claude, another AI, or a future developer) whenever you want to
regenerate, extend, or hand off this project.

**Role:** Expert Full-Stack Web Developer (PHP, MySQL, JavaScript, HTML, CSS).

**Objective:** Build a local-first, white-label hosting/domain/SSL/VPS billing portal that a
reseller can run under their own brand to invoice clients and hand out access credentials safely.

## Tech Stack
- Backend: PHP 8+, PDO (prepared statements only, no raw string concatenation in SQL)
- Frontend: HTML5, CSS3 (Tailwind or Bootstrap), vanilla JS/AJAX (fetch)
- Database: MySQL 8+
- Mail: PHPMailer over SMTP (never PHP's native `mail()`)
- Config: `.env` file, never hardcoded credentials

## Security Requirements (non-negotiable)
1. Passwords: `password_hash()` / `password_verify()` only.
2. API keys/tokens: store only a hashed value (e.g. `hash('sha256', $token)`) in the DB; show the
   raw token to the client exactly once at generation time.
3. OTP: 6-digit, expires in 10 minutes, max 3 verify attempts, then 60-second resend cooldown and
   a per-IP/per-email rate limit on OTP requests.
4. CSRF tokens on every state-changing form (register, checkout, UTR submission, admin approve).
5. Admin area has its own auth/session, separate from client sessions, plus its own login rate limit.
6. All DB access via PDO prepared statements.
7. Config/secrets loaded from `.env`, excluded from version control.

## Core Workflows

### 1. Client Auth
- Register → email OTP sent via PHPMailer/SMTP → OTP verified → account activated → login.

### 2. Product Selection & Dynamic Pricing
- Services: Shared Hosting, VPS, Domain, SSL.
- Tenure: 12 / 24 / 36 months.
- Discount: 24mo = flat ₹500 off; 36mo = flat ₹1000 off. Computed server-side at checkout
  (never trust the JS-displayed total) and mirrored live in the UI via JS.

### 3. Payment (manual, UPI/QR based)
- Checkout shows the admin's static QR + UPI ID (configurable in admin settings, not hardcoded).
- Client submits a UTR/reference number → order status = `pending_utr_verification`.
- Note: this manual-verification model is fine for small-scale personal resale; if this grows into
  collecting payments on behalf of many unrelated merchants, review RBI payment-aggregator rules —
  don't ship that assumption silently.

### 4. Admin Verification & Token Issuance
- Admin dashboard lists pending UTR submissions with order + client details.
- Admin manually cross-checks and clicks Approve → order status = `approved`,
  `approved_at` timestamp stored.
- A real cron job (`cron/generate_tokens.php`, run every minute via system cron) scans approved
  orders older than 5 minutes with no token yet, and generates one. A timestamp check on dashboard
  load is kept only as a UX fallback so the client sees "ready" instantly on the visit that crosses
  the 5-minute mark — the cron is the source of truth so a token is still issued even if the client
  never opens the dashboard.

### 5. API Key / Developer Handoff
- Client dashboard lets the client pre-register one or more developer emails against their account
  (whitelist) — a key is never usable with an arbitrary email typed in on the spot.
- To use the API key, caller supplies key + a whitelisted developer email → OTP sent to that email
  → OTP verified → system emails that developer a **scoped, revocable** access credential (not the
  client's raw hosting password) for the specific service purchased.
- Every use is logged (who, when, which order) and visible to the client.

## Deliverables
1. `sql/schema.sql` — full schema: users, otp_codes, products, orders, api_tokens,
   developer_emails, token_usage_log, admin_users, settings.
2. Clean extensionless URL routing (`.htaccess` + a tiny front controller/router).
3. Working UTR verification + cron-based 5-minute delayed token generation.
4. PHPMailer + SMTP setup for OTP and developer-credential emails, config via `.env`.
5. Frontend for dynamic 12/24/36-month pricing and the UTR submission form.
6. Everything modular and testable on a local server (XAMPP/WAMP/native PHP built-in server).

## Branding
Brand name, logo, and color palette are placeholders (`config/config.php` /
`public/assets/css/theme.css`) meant to be swapped for the reseller's own identity — never use
another company's name or logo.
