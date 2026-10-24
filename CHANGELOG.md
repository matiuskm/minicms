# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).
Each PRD phase ships as a minor release while below 1.0.0; `1.0.0` is cut when the release-level
Definition of Done (PRD section 11) is met.

## [Unreleased]

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

[Unreleased]: https://github.com/matiuskm/minicms/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/matiuskm/minicms/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/matiuskm/minicms/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/matiuskm/minicms/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/matiuskm/minicms/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/matiuskm/minicms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/matiuskm/minicms/releases/tag/v0.2.0
