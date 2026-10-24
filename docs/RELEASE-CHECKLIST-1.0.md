# Release 1.0.0 — Definition of Done (PRD section 11)

Status legend: **PASS** = verified by an automated test or a recorded check in this repository; **MANUAL** = can only be confirmed on the real server / device and is listed in "Before you go live".

## Release-level criteria

| Criterion | Result | Evidence |
|---|---|---|
| All Must features pass their passing points | **PASS** | Phases P-01..P-06 closed with their PPs (see `docs/PROGRESS-mini-cms.md`); 350+ Pest tests, 42 Vitest tests |
| Test coverage ≥ 70% for policy, sanitizer and status flow | **PASS** | Measured in CI (the `Coverage` step enforces a 70% minimum): **94.2% overall**; `PostPolicy` 90.0%, `HtmlSanitizer` 90.9%, `Tracker` 96.9%, `Backup` 87.7% |
| No Critical/High bugs open | **PASS** | No open issues; `composer audit` and `npm audit --audit-level=high` are clean and run on every push |
| NFR targets met | see below | |
| Docs complete | **PASS** | `README.md`, `docs/DEPLOY.md`, `CHANGELOG.md`, `docs/SA-*`, `docs/PRD-*` |

## Non-functional requirements

| NFR | Result | How it was checked |
|---|---|---|
| NFR-01 performance (TTFB, 100-user p95) | **MANUAL** | Public pages are cached per URL and built from ≤ 8 queries (tested), but no load test has been run against a real server |
| NFR-02 XSS / injection | **PASS** | 40-payload sanitizer suite (neutralised + idempotent), ORM-only queries, strict-hex colours, validated slugs/URLs |
| NFR-03 anti-deface | **PASS** in code, **MANUAL** on the server | Revisions, audit log, integrity check + alert, lockdown, signed updates are tested. Read-only code, DB grants and the no-PHP-in-uploads rule are server settings (`docs/DEPLOY.md`, `cms:check-production`) |
| NFR-04 no N+1 | **PASS** | Lazy loading throws outside production; query-count tests on every list/public page |
| NFR-05 transport | **MANUAL** | HSTS and secure cookies are sent/tested; TLS certificates are issued on the server |
| NFR-06 headers / CSP | **PASS** | Nonce-based CSP on every response; verified with a real Chrome across public and admin pages: 0 violations |
| NFR-07 session / CSRF | **PASS** | Session regeneration, idle timeouts, CSRF on all mutations |
| NFR-08 rate limiting | **PASS** | Login, reset, 2FA, search, upload, admin actions |
| NFR-09 secrets | **PASS** | No secrets in the repository; `.env.example` only; signing key lives in a GitHub secret |
| NFR-10 dependency hygiene | **PASS** | Audits in CI |
| NFR-11 admin surface | **PASS** | `noindex` on admin; generic login errors |
| NFR-12 uploads | **PASS** | Content-sniffed types, re-encoding, private sandboxed PDFs, per-user limits |
| NFR-13 retention | **PASS** | Revisions capped at 50; audit entries pruned after 12 months by `cms:prune-audit` (deploy-user cron) |
| NFR-14 accessibility / SEO | **PASS** in code, **MANUAL** audit | AA contrast warnings, alt text enforced, semantic markup, sitemap/meta/JSON-LD. No Lighthouse run yet |
| NFR-15 localisation | **PASS** | English UI, any-language content, per-site content language |
| NFR-16 observability | **PASS** | `/up` health endpoint, errors reported without leaking details |
| NFR-17 browser compatibility | **MANUAL** | Verified in current Chrome only (desktop and a 500 px viewport) |

## Beyond the original PRD (added on request)

Display names, a local-only 2FA bypass switch and `cms:reset-2fa`, visitor statistics with traffic sources (F-14), and one-click signed updates (F-15).

## Before you go live (all MANUAL)

1. Provision the VPS following `docs/DEPLOY.md`; run `php artisan cms:check-production` until it passes.
2. Apply `database/hardening/production-grants.sql` and confirm the app account cannot UPDATE/DELETE `audit_logs`.
3. Confirm nginx refuses PHP under `/storage/` (upload a harmless `test.txt`, then request `/storage/…/x.php` → 403/404).
4. Configure real SMTP and confirm an invitation, a password reset and a new-device notice arrive.
5. Set up the updater layout (`releases/ current shared`), cron and sudoers, then run a real update from this release to the next one.
6. Take a backup and rehearse a restore on a staging copy.
7. Run a short load test against the public pages and a Lighthouse pass; check Firefox and Safari.
