# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).
Each PRD phase ships as a minor release while below 1.0.0; `1.0.0` is cut when the release-level
Definition of Done (PRD section 11) is met.

## [Unreleased]

## [1.3.5] - 2026-10-25 — Updates can take their backup
### Fixed
- The update failed with "The backup failed" on installer-made servers: the backup folder was created by the site (`www-data`) with mode 0700, so the updater (`deploy`) could not write into it. The backup disk now creates group-writable folders and files (0770/0660).

## [1.3.4] - 2026-10-25 — Site name everywhere
### Fixed
- Changing "Site name" in Site settings only changed the public header. The admin sidebar still showed `APP_NAME` (the domain on installer-made servers) and the browser tab suffix was baked into the build (`Mini CMS`). Both now follow the setting, with `APP_NAME` as the fallback.

## [1.3.3] - 2026-10-25 — Installer fix
### Fixed
- `install.sh` stopped with MySQL error 1819 on servers that enforce `validate_password`: the generated database passwords were hex only. They now always contain upper case, lower case, a digit and a symbol (also valid under the STRONG policy).
- The installer log is appended to instead of being emptied on each run, so a re-run no longer wipes the log of the failed attempt.

## [1.3.2] - 2026-10-25 — Installer: stricter Nginx
### Added
- `install.sh` now writes a hardened Nginx setup: `server_tokens off`; slow-client timeouts; requests whose Host is not the configured domain (IP address, other names) are dropped (`444`); per-address request limit for the site (20 r/s, burst 100) and a tighter one for sign-in routes (30 r/min, burst 10, answered with `429`).

## [1.3.1] - 2026-10-25 — Installer fix
### Fixed
- `install.sh` did not delete itself after a successful install when started with a relative path (it changes directory first); it now remembers its absolute path.

## [1.3.0] - 2026-10-25 — Server installer
### Added
- `install.sh` (attached to every release): one command on a fresh Ubuntu 22.04/24.04 server checks requirements, installs PHP 8.4, Nginx, MySQL 8, Node 22, Supervisor and Certbot, installs the latest *signature-verified* release in the `releases/current/shared` layout with the least-privilege database accounts, obtains HTTPS, starts SSR and cron, creates the first admin and deletes itself.
### Fixed
- Server-side rendering could never start from a release archive: the SSR bundle imported `react` from `node_modules`, which the archive does not contain. The bundle is now self-contained.
- Test files were bundled into the browser build (an unused 470 kB chunk) and would have been pulled into the SSR bundle.

## [1.2.0] - 2026-10-25 — Text + image section
### Added
- Home page section **Text + image**: heading and rich text beside a portrait image (left or right), optional button; stacks on phones.

## [1.1.0] - 2026-10-25 — Home page builder
### Added
- Admin **Home page** screen: build the home page from ordered sections (Featured post, Hero image, Hero slider, Text, Post grid, Call to action, Blog), show/hide, reorder, add and remove them.
- Accessible, dependency-free slider (keyboard, pause on hover/focus, honours reduced motion).
- Images used on the home page are protected from deletion in Media.
### Changed
- The public home page props are now `sections` + `pager`; the default layout (featured block + blog list) looks as before.
- Admin form errors that belong to no visible field are summarised at the top of the Home page screen.

## [1.0.0] - 2026-10-25 — First stable release
Everything from 0.2.0 to 0.9.0 (identity and audit trail, taxonomy and media, editor, posts and pages with review workflow,
public site with SEO, appearance, hardening and backups, visitor statistics, signed one-click updates), plus:
### Added
- `cms:prune-audit`: removes audit entries older than 12 months (run with the migrator credentials, see `docs/DEPLOY.md`).
- `docs/RELEASE-CHECKLIST-1.0.md`: the definition of done checked against the PRD, with what remains to confirm on a real server.
- Code coverage is measured in CI.

## [0.9.0] - 2026-10-24 — P-08 One-click updates
### Added
- Admin **Updates** screen: installed vs latest version, release notes, "Check for updates", an **Update** button with live progress, a sidebar badge, and one email per new version.
- Safe update mechanism: the web app only requests an update; `scripts/apply-update.sh` (run by the deploy user) downloads the release, verifies its Ed25519 signature against the installed version's pinned key, backs up, runs migrations, switches releases atomically, health-checks and rolls back automatically on failure.
- Signed release pipeline (`.github/workflows/release.yml`, `scripts/build-release.sh`): releases ship with `vendor/` and compiled assets, so servers need only PHP.
- `scripts/test-updater.sh`: an end-to-end rehearsal (success, tampered archive, foreign key, downgrade, garbage request, failed health check).
- Commands: `cms:check-updates`, `cms:version`, `cms:maintenance`, `cms:record-update`.
### Docs
- `docs/DEPLOY.md`: release-directory layout, cron, sudoers, migrator account and rollback procedure for one-click updates.

## [0.8.0] - 2026-10-24 — P-07 Visitor statistics
### Added
- Cookie-less, first-party visit counting for public pages: no IP or user agent stored, unique visitors recognised only within a day, bots / link previews / staff / Do-Not-Track / prefetches ignored.
- Traffic sources from `utm_source`, the Referer header and in-app browsers (Instagram, Facebook, TikTok, LINE, X); Google/Bing/other sites; direct; inside the site.
- Statistics screen: views, unique visitors, daily chart, sources, most viewed, campaigns, referring websites, per-page source breakdown.
- Tracked link builder for sharing on Instagram, WhatsApp, Facebook, X, Telegram, TikTok, LINE, email, etc.
- Settings: switch counting off, retention 30–365 days; nightly pruning (`cms:prune-analytics`).
- `cms:check-production` also fails if any auth guard uses the legacy `token` driver.

## [0.7.0] - 2026-10-24 — P-06 Hardening & operations
### Added
- Nonce-based Content-Security-Policy on every response, plus COOP and a tighter Permissions-Policy.
- Security centre: lockdown (writers signed out and blocked), content-integrity check (every 15 minutes, email alert once per change, confirm or restore), backup status.
- Email notices for sign-ins from a new browser, role changes, password changes and 2FA being turned off.
- Daily backups (database + uploads, retention 14, off-site disk supported), `cms:restore`, failure alerts.
- `cms:check-production` deploy gate, `cms:check-integrity`, `docs/DEPLOY.md` (VPS guide) and per-account database grants.
- Role-aware dashboard: review queue or "sent back to you", recent activity, sign-in/integrity/backup health.
- Admin/editor actions are throttled per user; public search limited to 30 requests/minute.
### Changed
- Inertia's progress-bar styles ship in `app.css` instead of being injected at runtime (needed for the strict CSP).
- Page titles no longer repeat the site name.

## [0.6.0] - 2026-10-24 — P-05 Appearance
### Added
- Appearance screen: six built-in palettes (Light Classic, Dark, Ocean, Forest, Sunset, Monochrome), custom palettes (create, edit, duplicate, delete), one live palette at a time, live preview, WCAG AA contrast warnings.
- Typography: heading and body font from an allowlist (system sans/serif/mono, Open Sans, Roboto, Lato, Merriweather, Source Serif; self-hosted), base text size S/M/L, reset to defaults.
- `/theme.css`: the public theme as a versioned, cacheable stylesheet built only from validated values.
### Added (dev)
- `CMS_REQUIRE_ADMIN_2FA=false` skips the mandatory admin 2FA setup and the login challenge for local development only; it is ignored in production and the admin header shows a warning while it is off.
- `php artisan cms:reset-2fa <email>` removes 2FA from an account (lost authenticator) and ends its sessions.
### Fixed
- Public pages now link the theme after the app CSS with a selector that survives Vite re-inserting the app stylesheet.

## [0.5.0] - 2026-10-24 — P-04 Public site & settings
### Added
- Public site after `docs/design/reference-public.jpg`: home with featured post, "Latest post" list and card grid with numbered pagination; post, page, category, tag, author and blog archives; search; responsive layout.
- Server-side rendering for crawlers, meta/Open Graph tags, canonical URLs, Article JSON-LD, sitemap.xml, rss.xml, robots.txt, 301 redirects for renamed slugs, page cache with automatic invalidation.
- Generic 403/404/500/503 pages and maintenance mode.
- Site settings (name, tagline, logo, favicon, language, posts per page, header button, PDF size limit, mail sender) and header/footer menu editor.
- "Visit Website" link in the admin top bar and a real dashboard with role-scoped counts.
- Optional demo content: `php artisan db:seed --class=DemoContentSeeder`.
### Changed
- Uploaded image URLs are relative (`/storage/...`) so the sanitizer accepts them in content.
- Admin status messages (flash) are now shown.
### Fixed
- Home feature card: the title sits on a frosted (blurred, tinted) panel so it stays readable on light featured images, as in the design reference.
- Inertia page lookup in tests used a capitalised `Pages` path that only worked on case-insensitive filesystems.
### Removed
- Static `public/robots.txt` (served dynamically).

## [0.4.0] - 2026-10-24 — P-03 Posts & pages
### Added
- Posts with the review workflow (draft → pending review → published/scheduled/rejected → archived, trash), role-scoped access, policy-enforced transitions and an audit trail.
- Editor page after the design reference: tabs Content/Meta/SEO, side panel (author, date, category, tags, status), Save menu, preview, revisions, draft autosave, optimistic locking.
- Pages (admin only): one-level hierarchy, menu order, reserved addresses, publish/unpublish, trash/restore/purge.
- Revision history (latest 50) with restore, content integrity hash, 301 redirect records when a live slug changes.
- Scheduler command `cms:publish-scheduled`.
- Tag merge and published-post counters; deleting a category moves its posts to "Uncategorized"; media in use can no longer be deleted.
- Display name: a public byline separate from the login email and real name (never matches the email).
### Changed
- Sanitizer only accepts images from `/storage/uploads/`.
- CI runs on every pull request, including PRs stacked on phase branches.

## [0.3.0] - 2026-10-24 — P-02 Taxonomy, media & editor
### Added
- Admin shell restyled after `docs/design/reference-editor.jpg` (navy sidebar, light cards, system font stack, no external fonts).
- Categories (one level, protected "Uncategorized") and Tags (case-insensitive unique, writers can create via find-or-create).
- Media library: content-sniffed uploads, image re-encode + 3 variants, PDFs on a private disk served as sandboxed attachments, per-user scope, upload rate limits.
- TipTap rich-text editor (headings, formatting, lists, quote, code, table, align, captioned images, PDF links, YouTube/Vimeo embeds, admin-only HTML source, word count).
- Server-side allowlist HTML sanitizer (`HtmlSanitizer`, `SanitizedHtml` cast) and `POST /content/preview`.
### Removed
- Dark mode and the appearance settings page; the admin UI is light only.
### Changed
- Docs: PRD v1.3 adopts the UI reference in `docs/design/` (navy sidebar, tabbed editor with side panel); adds per-post/page SEO fields to the plan.

## [0.2.1] - 2026-10-24
### Fixed
- CI lint job: ignore generated `resources/js/ziggy.js` in ESLint/Prettier.

## [0.2.0] - 2026-10-24 — P-01 Identity & audit trail
### Added
- Login with generic errors, account lock (5 failures / 10 min → 15 min lock + email), per-IP rate limit.
- TOTP two-factor auth with replay guard and hashed one-time recovery codes; mandatory for admins.
- Password reset (60 min, single use, revokes sessions), invitations (48 h), min 12-char passwords, Argon2id.
- Active session list and revoke, idle timeout per role, disabled users kicked on next request.
- User management (invite, role change, disable/enable, force reset) with last-admin and self-protection.
- Append-only audit log (filter, CSV export) and `cms:make-admin` command.
- Baseline security headers (global), HSTS over HTTPS.
### Removed
- Starter-kit public registration, email verification, password confirmation and self-service account deletion.

## [0.1.0] - 2026-10-24 — P-00 Project setup
_Shipped inside the initial commit together with 0.2.0; no separate tag._
### Added
- Laravel 12 + React 19 + Inertia 2 (SSR) skeleton, MySQL, Pest 3, Vitest, CI with MySQL service and dependency audits.
- `Model::preventLazyLoading()` outside production (N+1 guard).

[Unreleased]: https://github.com/matiuskm/minicms/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/matiuskm/minicms/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/matiuskm/minicms/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/matiuskm/minicms/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/matiuskm/minicms/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/matiuskm/minicms/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/matiuskm/minicms/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/matiuskm/minicms/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/matiuskm/minicms/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/matiuskm/minicms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/matiuskm/minicms/releases/tag/v0.2.0
