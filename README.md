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

## Production

See `database/hardening/production-grants.sql` for least-privilege DB accounts (audit log is append-only).
