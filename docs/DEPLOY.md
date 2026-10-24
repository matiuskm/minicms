# Deploying to a VPS

One Linux VPS: Nginx + PHP-FPM 8.4, MySQL 8, Node 22+ (only for server-side rendering), Supervisor, cron.
Everything below is the minimum that the PRD's security requirements (NFR-03, NFR-05, NFR-09) assume.

## 1. Accounts and file permissions

| Account | Purpose | Rights |
|---|---|---|
| `deploy` | owns the code, runs `composer`, `npm`, `artisan migrate` | writes the code directory |
| `www-data` (PHP-FPM) | serves requests | **read-only** on the code; writes only `storage/` and `bootstrap/cache/` |

```bash
sudo chown -R deploy:deploy /var/www/cms
sudo chown -R www-data:www-data /var/www/cms/storage /var/www/cms/bootstrap/cache
sudo chmod -R u=rwX,g=rX,o= /var/www/cms        # nothing world-readable (the .env holds secrets)
sudo chmod -R g+w /var/www/cms/storage /var/www/cms/bootstrap/cache
```

If an attacker finds a bug in the app, they cannot rewrite its code (no web shell, no persistent deface).
`php artisan cms:check-production` verifies this ("Application code is not writable by this user").

## 2. Database users

Create two MySQL accounts (see [`database/hardening/production-grants.sql`](../database/hardening/production-grants.sql)):

- **migrator** – used only by `php artisan migrate --force` during a deploy (CREATE/ALTER/DROP).
- **app** – used by the running site. SELECT/INSERT/UPDATE/DELETE on application tables, and **only SELECT/INSERT on `audit_logs`**
  so the audit trail cannot be rewritten even by an attacker who owns the app.

Never use `root`. The app account must not have `FILE`, `SUPER`, `CREATE`, `ALTER` or `DROP`.

## 3. Environment file

Copy `.env.example` to `.env` (outside the web root, mode 640) and set at least:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=UTC
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
HASH_DRIVER=argon2id
CMS_REQUIRE_ADMIN_2FA=true         # also forced on in production, whatever this says
DB_USERNAME=cms_app
MAIL_MAILER=smtp                   # plus host/user/password
BACKUP_DISK=backups                # or an off-site disk such as s3
```

Run the checklist; it exits non-zero if anything is off, so it can gate your deploy script:

```bash
php artisan cms:check-production
```

## 4. Build and release

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build && npm run build:ssr
php artisan migrate --force            # as the migrator account (set DB_* temporarily or via env)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan cms:make-admin you@example.com --name="Your Name"   # first run only; prints a one-time password
php artisan inertia:start-ssr          # see Supervisor below
```

The first admin must set up two-factor authentication at first sign-in.

## 5. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.example;
    root /var/www/cms/public;
    index index.php;
    client_max_body_size 12m;                  # >= upload limits; PHP: upload_max_filesize = post_max_size = 12M

    # TLS: certificates from Let's Encrypt (certbot). HSTS and the CSP are sent by the application.

    location ~ /\.(?!well-known) { deny all; }

    # Uploaded files are data, never code (TC-F05-05).
    location ^~ /storage/ {
        location ~ \.php$ { return 403; }
        add_header X-Content-Type-Options nosniff always;
        expires 30d;
    }
    location ^~ /build/ { expires 1y; add_header Cache-Control "public, immutable"; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ ^/(?!index\.php).+\.php$ { return 404; }   # only index.php may execute
}
server { listen 80; server_name your-domain.example; return 301 https://$host$request_uri; }
```

PHP-FPM: `expose_php = Off`, `display_errors = Off`, `session.cookie_httponly = 1`, `opcache.validate_timestamps = 0` (restart after deploys).

## 6. Background processes

Supervisor keeps server-side rendering running (search engines then see full HTML):

```ini
[program:cms-ssr]
command=php /var/www/cms/artisan inertia:start-ssr
user=www-data
autostart=true
autorestart=true
```

Cron runs the scheduler once a minute. It publishes scheduled posts, checks content integrity every 15 minutes, takes the daily backup and prunes old visit records:

```cron
* * * * * cd /var/www/cms && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Backups and restore

`php artisan cms:backup` (daily at 02:30 via the scheduler) writes `backup-<time>-db.sql.gz` and `-media.tar.gz` to the backup disk and keeps
the latest 14 sets. A failure emails the admins. **Point `BACKUP_DISK` at storage on another machine**; a backup on the same disk
does not survive losing the server.

**Restore drill** (do this once on a staging copy before you need it):

```bash
php artisan cms:restore backup-20261024-023000-db.sql.gz --media=backup-20261024-023000-media.tar.gz --force
php artisan optimize:clear
php artisan cms:check-integrity
```

`--force` is required because the restore replaces every table in the configured database.

### Visitor statistics

Visits are counted by the application itself (no cookies, no IPs stored). Behind a proxy or CDN, configure trusted proxies so
`request()->ip()` is the visitor and not the proxy; otherwise everyone looks like one visitor. A busy site (tens of thousands of views a day)
should use Redis as the cache driver, because counting does a few cache operations per visit.

## 8. When something looks wrong

| Situation | What to do |
|---|---|
| Suspected account takeover | Admin → **Security → Turn on lockdown** (writers are signed out), reset passwords from **Team**, review **Audit log** |
| Alert: "content changed outside the CMS" | Admin → **Security**; open the item and restore an earlier revision, or confirm the change |
| Lost authenticator | `php artisan cms:reset-2fa user@example.com` on the server |
| Site must go offline briefly | Admin → **Site settings → Maintenance mode** (admins can still work) |
| Bad deploy | Restore the previous release directory, `php artisan optimize:clear`, restart PHP-FPM and the SSR process |

## 9. Routine

- Weekly: `composer audit` and `npm audit` (CI also runs them on every push).
- After each release: `php artisan cms:check-production`, then confirm the Security page shows integrity OK and a recent backup.
- Keep PHP, MySQL, Node and the OS patched; subscribe to Laravel security announcements.
