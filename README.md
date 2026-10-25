# Mini CMS

A secure, single-site CMS: posts, pages, categories and tags with a visual editor and a review workflow; a public site with SEO and
server-side rendering; global colour palettes and fonts; visitor statistics without cookies; content-integrity alerts, lockdown, backups;
a home page builder, and signed one-click updates. Admin and Writer roles, mandatory 2FA for admins.

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

Tests: `./vendor/bin/pest` and `npm test`. Admins must set up 2FA on first login (local development can set
`CMS_REQUIRE_ADMIN_2FA=false` in `.env`; the switch is ignored in production).

## Versioning

[SemVer](https://semver.org); the current version is in [`VERSION`](VERSION) (exposed as `config('app.version')`)
and history in [CHANGELOG.md](CHANGELOG.md). One minor release per PRD phase until `1.0.0`.

Release steps: update `CHANGELOG.md` and `VERSION` → commit `chore(release): vX.Y.Z` →
`git tag -a vX.Y.Z -m "vX.Y.Z"` → `git push --follow-tags`.
Branches: `main` is always releasable; each phase is developed on `phase/p-0x-name` and merged via PR.

## Server-side rendering

Public pages are rendered on the server for crawlers by a Node process: `php artisan inertia:start-ssr`
(after `npm run build:ssr`). Without it the site still works (client-side rendering) but search engines see less.
Production: keep it alive with supervisord or systemd. Optional sample content: `php artisan db:seed --class=DemoContentSeeder`.

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

Full VPS guide: [docs/DEPLOY.md](docs/DEPLOY.md). Gate a deploy with `php artisan cms:check-production`.

See `database/hardening/production-grants.sql` for least-privilege DB accounts (audit log is append-only).

Web server rules for uploads (NFR-03, manual check TC-F05-05): never execute PHP from the storage directory, e.g. in nginx

```nginx
location ^~ /storage/ { location ~ \.php$ { return 403; } add_header X-Content-Type-Options nosniff; }
```

## Install on a server

On a fresh Ubuntu 22.04/24.04 VPS: `curl -fsSLO https://github.com/matiuskm/minicms/releases/latest/download/install.sh && sudo bash install.sh --domain example.com --email you@example.com`. See `docs/DEPLOY.md`.
