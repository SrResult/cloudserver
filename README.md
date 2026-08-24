# White-Label Hosting & Domain Billing Panel

A local, white-label billing portal for reselling hosting, VPS, domains, and SSL — with email-OTP
registration, dynamic 12/24/36-month pricing, manual UPI QR + UTR payment verification, admin
approval, a 5-minute delayed API-key issuance, and a whitelisted-developer-email credential
handoff. See `PROMPT.md` for the full design spec this was built from.

## 1. Requirements

- PHP 8.1+ with the `pdo_mysql`, `openssl` extensions
- MySQL 8+ (or MariaDB 10.5+)
- Composer
- Apache with `mod_rewrite` (for clean URLs), or run with PHP's built-in server for local testing

## 2. Setup

```bash
cd hosting-panel
composer install                      # installs PHPMailer
cp .env.example .env
```

Edit `.env`:
- `DB_*` — your local MySQL credentials
- `SMTP_*` — a real SMTP account (Gmail: use an **App Password**, not your login password;
  SendGrid/Mailgun also work)
- `APP_BRAND_NAME` — your own brand name (this is a white-label panel — don't use someone else's
  name or logo)
- Generate `APP_ENCRYPTION_KEY` (used to encrypt stored hosting credentials at rest):
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
  paste the output as `APP_ENCRYPTION_KEY=...` in `.env`.

Create the database:

```bash
mysql -u root -p < sql/schema.sql
```

Create your admin login:

```bash
php scripts/make_admin.php "Your Name" you@example.com "a-strong-password"
```

Replace the payment QR placeholder at `public/assets/img/payment-qr-placeholder.png` with your
real UPI QR, or upload one from **Admin → Settings** after logging in — and set your real UPI ID
there too.

## 3. Run locally

**Option A — PHP's built-in server (fastest, includes a router so clean URLs work without Apache):**

```bash
php -S localhost:8000 -t public router.php
```

Visit `http://localhost:8000`. Admin panel: `http://localhost:8000/admin/login`.

**Option B — Apache/XAMPP:** point the vhost's document root at the `public/` folder (not the
project root — everything outside `public/` should stay unreachable from the browser) and make
sure `AllowOverride All` is set so `public/.htaccess` clean URLs work. `router.php` is only used by
Option A — Apache doesn't need it.

**No MySQL server handy yet?** Set `DB_DRIVER=sqlite` in `.env` (instead of the default `mysql`)
and point `DB_NAME` at a file path, e.g. `DB_NAME=/absolute/path/to/storage/dev.sqlite`. Then load
`sql/schema.sqlite.sql` instead of `sql/schema.sql`:

```bash
mkdir -p storage
php -r "\$p=new PDO('sqlite:storage/dev.sqlite'); \$p->exec(file_get_contents('sql/schema.sqlite.sql'));"
```

This is meant for quick local testing without installing MySQL — switch `DB_DRIVER` back to
`mysql` (and rerun `sql/schema.sql` against a real MySQL database) before going anywhere near real
client data or payments.

## 4. Set up the cron job (required for token issuance)

The dashboard has a fallback that issues a token the moment a client happens to load it after the
5-minute delay, but the real, always-on mechanism is a system cron job:

```
* * * * * /usr/bin/php /full/path/to/hosting-panel/cron/generate_tokens.php >> /full/path/to/hosting-panel/cron/cron.log 2>&1
```

On Windows (XAMPP), use Task Scheduler to run the same command every minute instead.

## 5. How the flows work

**Client registration** — sign up → OTP emailed → verify → dashboard.

**Ordering** — pick a product → choose 12/24/36 months (price updates live via JS, but the amount
actually charged is always recalculated server-side) → checkout shows your QR + UPI ID → client
submits their UTR → order becomes `pending_utr_verification`.

**Admin verification** — Admin → Orders → cross-check the UTR against your bank/UPI app → Approve
or Reject. On approve, `approved_at` is stamped.

**Token issuance** — the cron job (or the dashboard fallback) checks every approved order without a
token yet; once 5 minutes have passed since `approved_at`, a token is generated. The raw key is
shown once (dashboard banner + email) and only its SHA-256 hash is stored — it cannot be recovered
later, only revoked/reissued.

**Provisioning real access** — after approving an order, go to **Admin → Orders → Set credentials**
and enter the real hostname/username/password for the service you provisioned on your actual
hosting/VPS/domain provider. This is stored encrypted (AES-256-GCM using `APP_ENCRYPTION_KEY`).

**Developer handoff** — the client adds trusted developer emails on their dashboard first (a
whitelist). A developer then goes to `/api-access`, enters the API key + their email; if that email
is whitelisted for that key, an OTP is emailed to them; once verified, the credentials are emailed
to that developer — never displayed on screen, and every redemption is logged in
`token_usage_log` (visible to you as admin via the database for now — add an admin view if you want
it in the UI).

## 6. Security notes worth keeping in mind

- Passwords are hashed with `password_hash()`; API tokens are stored only as SHA-256 hashes.
- Every state-changing form is CSRF-protected.
- OTP requests and verifications are rate-limited per session; consider adding a database/IP-based
  limiter too if you expose this beyond your own local network.
- The manual QR/UTR payment flow is meant for small-scale personal resale. If you start collecting
  payments for many unrelated third parties at volume, look into whether RBI payment-aggregator
  rules apply to you — this project doesn't attempt to answer that for you.
- Rotate `APP_ENCRYPTION_KEY` and `SMTP_PASS` if either ever leaks; re-encrypting stored credentials
  after a key rotation is a manual step (decrypt with the old key, re-encrypt with the new one).

## 7. Project structure

```
hosting-panel/
├── admin, config, includes/ (moved as needed; admin is web-served)
├── config/            .env loader, PDO connection
├── includes/          shared helpers: auth, mailer/OTP, token issuer, crypto
├── public/            the actual webroot — point your server here
│   ├── admin/          admin login, orders, pricing, settings, credentials
│   ├── assets/         css/js/img
│   └── *.php           client-facing pages (clean URLs via .htaccess)
├── cron/              generate_tokens.php — run every minute
├── scripts/           make_admin.php — CLI admin bootstrap
└── sql/schema.sql
```

## 8. Customize the brand

Swap `APP_BRAND_NAME` in `.env`, the CSS palette in `public/assets/css/theme.css`
(`--primary`, `--primary-dark`), and drop your own logo into `public/assets/img/` and reference it
from the header markup in each page's `<header class="topbar">` block.
