# Mini CMS

Single-site CMS: Laravel 12, React 19, Inertia 2 (SSR), MySQL. Specs live in [`docs/`](docs/)
(`SA-*` analysis, `PRD-*` requirements, `PROGRESS-*` phase tracker).

## Local setup (Laravel Herd)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# create databases mini_cms and mini_cms_test, then fill DB_* in .env
php artisan migrate
php artisan cms:make-admin you@example.com --name="Your Name"
herd link mini-cms && herd secure mini-cms   # https://mini-cms.test
npm run build
```

Tests: `./vendor/bin/pest` and `npm test`. Admins must set up 2FA on first login.

## Versioning

[SemVer](https://semver.org); the current version is in [`VERSION`](VERSION) (exposed as `config('app.version')`)
and history in [CHANGELOG.md](CHANGELOG.md). One minor release per PRD phase until `1.0.0`.

Release steps: update `CHANGELOG.md` and `VERSION` → commit `chore(release): vX.Y.Z` →
`git tag -a vX.Y.Z -m "vX.Y.Z"` → `git push --follow-tags`.
Branches: `main` is always releasable; each phase is developed on `phase/p-0x-name` and merged via PR.

## Scheduler

Scheduled posts are published by `php artisan cms:publish-scheduled`, run every minute by Laravel's scheduler.
Production needs one cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
(Herd/local: run `php artisan schedule:work` while testing).

## Uploads

Images (JPG/PNG/GIF/WebP ≤ 5 MB) are re-encoded and stored on the public disk (`php artisan storage:link`);
PDFs (≤ 10 MB) stay on the private disk and are served through `/files/{uuid}`.
PHP's own limits must be at least as high: set `upload_max_filesize = 12M` and `post_max_size = 12M`
(Herd: Settings → PHP → Limits; VPS: `php.ini`).

## Production

See `database/hardening/production-grants.sql` for least-privilege DB accounts (audit log is append-only).

Web server rules for uploads (NFR-03, manual check TC-F05-05): never execute PHP from the storage directory, e.g. in nginx

```nginx
location ^~ /storage/ { location ~ \.php$ { return 403; } add_header X-Content-Type-Options nosniff; }
```
