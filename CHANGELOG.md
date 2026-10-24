# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).
Each PRD phase ships as a minor release while below 1.0.0; `1.0.0` is cut when the release-level
Definition of Done (PRD section 11) is met.

## [Unreleased]
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

[Unreleased]: https://github.com/matiuskm/minicms/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/matiuskm/minicms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/matiuskm/minicms/releases/tag/v0.2.0
