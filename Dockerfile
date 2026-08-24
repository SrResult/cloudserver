FROM php:8.3-cli

RUN docker-php-ext-install pdo pdo_sqlite pdo_mysql \
    && apt-get update && apt-get install -y unzip git \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader || true
RUN mkdir -p storage

EXPOSE 8080
CMD php -r '$keys=["DB_DRIVER","DB_HOST","DB_NAME","DB_USER","DB_PASS","SMTP_HOST","SMTP_PORT","SMTP_USER","SMTP_PASS","SMTP_FROM_NAME","SMTP_ENCRYPTION","APP_URL","APP_BRAND_NAME","APP_TIMEZONE","APP_ENCRYPTION_KEY","OTP_EXPIRY_MINUTES","OTP_MAX_ATTEMPTS","TOKEN_DELAY_MINUTES","APP_DEBUG_FIXED_OTP"]; $out=""; foreach($keys as $k){$v=getenv($k); if($v!==false){$out.=$k."=".$v."\n";}} file_put_contents(".env",$out);' \
    && if [ ! -f storage/dev.sqlite ]; then php -r '$p=new PDO("sqlite:storage/dev.sqlite"); $p->exec(file_get_contents("sql/schema.sqlite.sql"));'; fi \
    && php scripts/make_admin.php "Sunil Kumar Saini" admin@example.com "Admin@12345" \
    && php -S 0.0.0.0:${PORT:-8080} -t public router.php
