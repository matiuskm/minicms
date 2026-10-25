# Deploying to a VPS

## Quick start: one command

On a fresh **Ubuntu 22.04 or 24.04** server (root access, DNS for your domain already pointing at it):

```bash
curl -fsSLO https://github.com/matiuskm/minicms/releases/latest/download/install.sh
sudo bash install.sh --domain example.com --email you@example.com
```

`install.sh` checks the server first (OS, memory, disk, ports 80/443, GitHub access, DNS, MySQL access) and prints what it will add.
Add `--dry-run` to see only that report. It then installs what is missing (PHP 8.4, Nginx, MySQL 8, Node 22 for server-side rendering,
Supervisor, Certbot), installs the latest release **only if its Ed25519 signature verifies**, creates the locked-down layout and the
two database accounts described below, obtains the HTTPS certificate, starts the background processes, creates the first admin
(a one-time password is printed at the end and stored nowhere), and finally **deletes itself**. The log is `/var/log/cms-install.log`
(root only, contains no passwords). If it stops half-way, run it again: it removes its own leftovers first.
Options: `--name`, `--version`, `--no-tls` (TLS ends at a proxy/CDN), `--yes` (no confirmation), `--keep` (keep the file).

What it cannot do for you: real SMTP settings, an off-site `BACKUP_DISK`, and the firewall; the closing summary lists them.
Everything the script does is the manual procedure below, so read on if you prefer to do it by hand or need a different OS.


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

### Audit log retention

The site's database account can only add to `audit_logs`, so trimming it cannot be done by the web scheduler. Run it weekly from the deploy user's cron with the migrator credentials (entries older than 12 months are deleted):

```cron
30 3 * * 0 cd /var/www/cms/current && set -a && . /var/www/cms/shared/.env.migrator && set +a && php artisan cms:prune-audit >> /var/www/cms/shared/storage/logs/prune.log 2>&1
```

### Visitor statistics

Visits are counted by the application itself (no cookies, no IPs stored). Behind a proxy or CDN, configure trusted proxies so
`request()->ip()` is the visitor and not the proxy; otherwise everyone looks like one visitor. A busy site (tens of thousands of views a day)
should use Redis as the cache driver, because counting does a few cache operations per visit.

## 8. One-click updates (optional, recommended)

The admin shows **Updates** with a badge when a new release exists, release notes, and an **Update** button, like WordPress.
Unlike WordPress, the website itself never gets permission to change its own code (that is what keeps §1 true).
The button only *asks*; a small root-free process owned by the `deploy` user does the work.

```
Admin clicks Update ──► app writes shared/storage/app/updates/request.json (just a version number)
cron (every minute, as deploy) ──► scripts/apply-update.sh
    1. version must be newer than the installed one            (no downgrades, garbage is ignored)
    2. downloads the release + signature from the project's release page
    3. verifies the Ed25519 signature with release-public.key of the INSTALLED version   (tampered / foreign archives are rejected)
    4. takes a backup (aborts if that fails), switches on maintenance mode
    5. unpacks into releases/<version>, links the shared .env and storage, runs the migrations
    6. switches `current` to the new release, rebuilds caches, reloads PHP-FPM and the SSR process
    7. health check; if the new version does not answer the previous release is restored automatically
    8. records the result in the audit log, emails the admins, keeps the last 3 releases
```

Releases are built by GitHub Actions (`.github/workflows/release.yml`) when a tag `vX.Y.Z` is pushed: the archive already contains
`vendor/` and the compiled assets, so **the server needs only PHP — no Composer, no Node**. The archive is signed with a key kept
as the repository secret `RELEASE_SIGNING_KEY`; only the public half is in the code. Cut a release from a green `main`:
`git tag -a v1.2.3 -m v1.2.3 && git push origin v1.2.3` (the workflow refuses a tag that does not match `VERSION` or has no `CHANGELOG.md` section).

### Server setup (once)

Use a release-directory layout instead of a single checkout:

```
/var/www/cms/
├── current   -> releases/1.0.0        # nginx root is /var/www/cms/current/public
├── releases/1.0.0/ …
└── shared/
    ├── .env                           # mode 640, owner deploy, group www-data
    ├── .env.migrator                  # DB_USERNAME=cms_migrator / DB_PASSWORD=…  (mode 600, deploy only)
    └── storage/                       # owner www-data; storage/app/updates must be group-writable by deploy
```

```bash
# first install: unpack the latest release archive into releases/<version>, then
ln -s releases/<version> /var/www/cms/current
ln -s /var/www/cms/shared/.env /var/www/cms/releases/<version>/.env
rm -rf releases/<version>/storage && ln -s /var/www/cms/shared/storage releases/<version>/storage
ln -s /var/www/cms/shared/storage/app/public releases/<version>/public/storage
sudo mkdir -p /var/www/cms/shared/storage/app/updates
sudo chown -R www-data:deploy /var/www/cms/shared/storage/app/updates && sudo chmod 2775 /var/www/cms/shared/storage/app/updates
```

Add to `.env`: `CMS_UPDATES=true` (and `CMS_UPDATE_REPO=owner/repo` if you forked). Allow `deploy` to reload services — nothing else:

```
# /etc/sudoers.d/cms-deploy
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm, /usr/bin/supervisorctl restart cms-ssr
```

Cron for the deploy user (this replaces running `schedule:run` as another user; both can coexist):

```cron
* * * * * APP_ROOT=/var/www/cms /var/www/cms/current/scripts/apply-update.sh >> /var/www/cms/shared/storage/logs/updater.log 2>&1
```

Migrations of a release must stay **backward compatible with the previous release** (add columns/tables, do not drop or rename in the same release):
the automatic rollback restores the previous *code*, it does not undo database changes. Your restore point for a bad migration is the backup taken in step 4.

Rehearse everything on a laptop without a server: `scripts/build-release.sh 0.9.0 /tmp/rel && scripts/test-updater.sh /tmp/rel/minicms-v0.9.0.tar.gz <signing-key-file>`
(success, tampered archive, foreign key, downgrade, garbage request, failed health check with rollback).

If `CMS_UPDATES` is off the screen still tells admins when a release exists and emails them once per version; they deploy it manually.

## 9. When something looks wrong

| Situation | What to do |
|---|---|
| Suspected account takeover | Admin → **Security → Turn on lockdown** (writers are signed out), reset passwords from **Team**, review **Audit log** |
| Alert: "content changed outside the CMS" | Admin → **Security**; open the item and restore an earlier revision, or confirm the change |
| Lost authenticator | `php artisan cms:reset-2fa user@example.com` on the server |
| Site must go offline briefly | Admin → **Site settings → Maintenance mode** (admins can still work) |
| Bad deploy or update | Point `current` back at the previous directory under `releases/` (`ln -sfn releases/<old> current`), `php artisan optimize:clear`, reload PHP-FPM and the SSR process. If a migration was the problem, restore the pre-update backup |
| Update stuck on "waiting" | The cron entry is not running as `deploy`, or `shared/storage/app/updates` is not writable by it. Check `shared/storage/logs/updater.log` |

## 10. Routine

- Weekly: `composer audit` and `npm audit` (CI also runs them on every push).
- After each release: `php artisan cms:check-production`, then confirm the Security page shows integrity OK and a recent backup.
- Keep PHP, MySQL, Node and the OS patched; subscribe to Laravel security announcements.
