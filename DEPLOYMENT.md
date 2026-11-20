# Deploying the POS

The app is PHP 8 + PostgreSQL + plain JavaScript. Pick **one** way to run it:

| Option | Best for | Effort |
|---|---|---|
| **A. Docker** (recommended) | Any VPS or a spare PC in the shop | ~10 min |
| **B. Ubuntu server** with Apache or nginx | You already manage a Linux server | ~30 min |
| **C. Windows / XAMPP** | One shop computer, no internet exposure | see `pos/README.md` |

> Shared hosting that only offers MySQL will **not** work: this app needs PostgreSQL (`pdo_pgsql`).

---

## A. Docker

1. Install Docker (with the Compose plugin) on the server.
2. Copy this whole folder to the server, then:
   ```bash
   cp .env.example .env
   nano .env            # set DB_PASSWORD (long, random) and ADMIN_PASSWORD (8+ characters)
   docker compose up -d --build
   ```
3. Open `http://SERVER-IP:8080` (by default only that machine can reach it; see *Network* below).
4. Sign in as `ADMIN_USERNAME` / `ADMIN_PASSWORD`. If you left `ADMIN_PASSWORD` empty, a random one is printed **once**:
   ```bash
   docker compose logs app | grep -A4 "First login"
   ```

The database tables are created and upgraded automatically each time the container starts. Your data lives in the `pgdata` volume and survives updates.

**Network and HTTPS**

- *Only this PC / your own reverse proxy:* keep `BIND_ADDR=127.0.0.1` (default).
- *Other computers on the shop network:* set `BIND_ADDR=0.0.0.0` (traffic is **not encrypted** on plain HTTP; fine on a trusted LAN only).
- *Internet with automatic HTTPS:* point a domain's DNS at the server, open ports 80 and 443, then in `.env` set `DOMAIN=pos.yourshop.co.ke` and `TRUST_PROXY=1`, and run
  `docker compose --profile https up -d --build`. Use `TRUST_PROXY=1` **only** when the app is reachable solely through that proxy (keep `BIND_ADDR=127.0.0.1`).

**Updating:** copy the new files over the old ones, then `docker compose up -d --build`.

---

## B. Ubuntu server (Apache)

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-pgsql postgresql
sudo a2enmod headers deflate rewrite

# database + a dedicated user (replace the password)
sudo -u postgres psql -c "CREATE USER pos WITH PASSWORD 'CHANGE-ME-long-random';" \
                      -c "CREATE DATABASE pos_db OWNER pos;"

# app files: copy the CONTENTS of the pos/ folder to /var/www/pos
sudo mkdir -p /var/www/pos && sudo cp -r pos/. /var/www/pos/
sudo chown -R www-data:www-data /var/www/pos
```

Create `/var/www/pos/api/config.local.php` (never commit it; it is blocked from the web):

```php
<?php
return [
    'dbname'   => 'pos_db',
    'user'     => 'pos',
    'password' => 'CHANGE-ME-long-random',
    // 'trust_proxy' => true,   // only behind a reverse proxy you control
];
```
(No `'env'` line = production mode, which is what you want on a server.)

Create the tables and the first admin (the password is printed once; or set `ADMIN_PASSWORD=...` in front of the command):

```bash
cd /var/www/pos && sudo -u www-data ADMIN_USERNAME=admin php bin/install.php
```

Apache site (`/etc/apache2/sites-available/pos.conf`), then `sudo a2ensite pos && sudo systemctl reload apache2`:

```apache
<VirtualHost *:80>
    ServerName pos.yourshop.co.ke
    DocumentRoot /var/www/pos
    <Directory /var/www/pos>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

HTTPS (free certificate): `sudo apt install certbot python3-certbot-apache && sudo certbot --apache -d pos.yourshop.co.ke`

**nginx instead of Apache?** `.htaccess` files are ignored by nginx, so add these rules to the server block (before the `\.php$` block):

```nginx
root /var/www/pos;  index index.php;
location ~ ^/(database|bin)/                 { deny all; }
location ~ ^/api/(?!index\.php$).*\.php$     { deny all; }
location = /setup.php                        { deny all; }
location ~ /\.                               { deny all; }
location ~* \.(sql|md|sh|example|env|ya?ml)$ { deny all; }
location ~* \.(js|css)$                      { expires 1y; add_header Cache-Control "public, immutable"; }
location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
```
and install `php8.3-fpm php-pgsql` instead of the Apache module. Use `config.local.php` for settings (PHP-FPM does not see shell environment variables).

**Updating:** copy the new files over `/var/www/pos` (keep `api/config.local.php`), then `sudo -u www-data php bin/install.php`. It is safe to repeat; the app also upgrades its own database structure on first use.

---

## Settings reference

Environment variables (Docker/`.env`) or the same names as keys in `api/config.local.php`:

| Variable | Meaning | Default |
|---|---|---|
| `DATABASE_URL` | `postgres://user:pass@host:5432/db?sslmode=require` (overrides the `DB_*` values; typical for hosted PostgreSQL) | – |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASSWORD` | PostgreSQL connection | `localhost` `5432` `pos_db` `postgres` (empty) |
| `DB_SSLMODE` | `require` for most hosted PostgreSQL | off |
| `APP_ENV` | `production` or `development` | `production` |
| `APP_DEBUG` | show technical errors in the browser (**never** on a live server) | off |
| `APP_TIMEZONE` | used for "today", reports and receipts | `Africa/Nairobi` |
| `ADMIN_USERNAME` `ADMIN_PASSWORD` | first admin, created only if no admin exists | `admin`, random |
| `TRUST_PROXY` | believe `X-Forwarded-For/Proto` from ONE proxy in front | off |
| `SESSION_HOURS` | idle time before sign-out | 12 |
| `SEED_SHOP` | load the starter products at install | off |

---

## After deploying: checklist

1. **Run the smoke test** from any computer (it changes no data):
   ```bash
   bash pos/bin/smoke-test.sh https://pos.yourshop.co.ke admin 'your-password'
   ```
   Every line should say `[ ok ]`. It checks the database link, login, role permissions, that internal files cannot be downloaded, and the security headers.
2. In the app: **Settings** (store name, phone, currency, tax %), then **Products** (press *Load shop products* for the starter list, then fix prices and stock), **Users** (only an admin can create managers and cashiers here).
3. Make a test sale, print/preview the receipt, then **void** it to put the stock back.
4. Set up **backups** (below) and do one test restore.
5. Use **HTTPS** whenever the POS is reachable from the internet.

## Backups

```bash
# Ubuntu server (needs postgresql-client); nightly at 02:00, keeps 14 days in /var/backups/pos
( crontab -l 2>/dev/null; echo "0 2 * * * ENV_FILE=/etc/pos.env /var/www/pos/bin/backup.sh >> /var/log/pos-backup.log 2>&1" ) | crontab -
#   /etc/pos.env contains lines like  DB_NAME=pos_db  DB_USER=pos  DB_PASSWORD=...   (chmod 600)

# Docker
docker compose exec -T db pg_dump -U "$DB_USER" -Fc "$DB_NAME" > pos-$(date +%F).dump
```
Restore into an empty database: `pg_restore --clean --if-exists -d pos_db pos-2026-01-31.dump`
(Docker: `docker compose exec -T db pg_restore --clean --if-exists -U "$DB_USER" -d "$DB_NAME" < pos-2026-01-31.dump`.)
Copy backups **off the server** too (another computer, cloud storage).

## Security notes

- No default passwords exist any more. Older databases that still have `admin / admin123` or `cashier / cashier123` keep working, but those accounts are forced to choose a new password at their next login.
- Logins are rate-limited (5 wrong tries per account per place, 30 per place, then a 15-minute pause).
- Cashiers cannot see cost prices or suppliers (the server removes them, not just the screen).
- Sessions are HttpOnly, SameSite=Strict, Secure on HTTPS, and expire after idle time. Every change request must carry the app's own header (CSRF guard).
- Database error details go to the server log, not to the browser. In Docker: `docker compose logs app`; Apache: `/var/log/apache2/error.log`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Cannot reach the database" | Check `DB_*` / `config.local.php`; is PostgreSQL running? Open `/api/index.php?route=health`. |
| "tables are not installed yet" | Run `php bin/install.php` (Docker does it automatically at start). |
| Page loads but everything says "server did not answer properly" | `docker compose logs app` or the Apache error log; usually `pdo_pgsql` missing or wrong DB password. |
| Sign-in works but you are logged out immediately behind a proxy | Set `TRUST_PROXY=1` so the app knows the visit was HTTPS; make sure the proxy sends `X-Forwarded-Proto`. |
| `setup.php` says it is switched off | Intended in production. Use `php bin/install.php`. |
| Locked out after wrong passwords | Wait 15 minutes, or have an admin reset it under Users. |
| Lost the admin password | `docker compose exec app php -r 'require "/var/www/html/api/installer.php"; $c=require "/var/www/html/api/config.php"; $p=pos_connect($c,$c["dbname"]); $s=pos_random_password(); $p->prepare("UPDATE users SET password_hash=?, active=TRUE WHERE username=?")->execute([password_hash($s,PASSWORD_DEFAULT),"admin"]); echo "New password: $s\n";'` |
