# Progress — Mini CMS
PRD: docs/PRD-mini-cms-2026-10-24.md  |  Started: 2026-10-24
Project dir: repo root (this repo)

| Phase | Status | Passing points | Evidence | Commit |
|---|---|---|---|---|
| P-00 Project setup | done (awaiting user review) | app boots, test runner runs, migrations apply: PASS | https://mini-cms.test → 200; Pest 27 passed; Vitest 1 passed; `migrate:status` 3 Ran on MySQL; `composer audit` + `npm audit` 0 vulns; `build` + `build:ssr` OK | not committed (no git repo) |
| P-01 Identitas & jejak | done (awaiting user review) | PP-F01-01..03, PP-F02-01..02, PP-F11-01 PASS; PP-F11-02 PASS (model) + MANUAL CHECK (DB grants) | Pest 48 passed (225 assertions); Vitest 1; tsc + eslint clean; build + build:ssr OK; composer/npm audit 0; Herd smoke: headers + 404 /register + 302 /admin/users | not committed (no git) |
| P-02 Taksonomi, media, editor | todo | | | |
| P-03 Konten | todo | | | |
| P-04 Situs publik & pengaturan | todo | | | |
| P-05 Tampilan | todo | | | |
| P-06 Hardening & operasi | todo | | | |

## Log
- 2026-10-24 P-00: pre-flight OK (PHP 8.4.25, Composer 2.10.2, Node 26.5.0, npm 11.14.1, MySQL 9.2.0, git 2.54). Folder bukan git repo; git init menunggu persetujuan user. Gallery/Slider ditunda ke Phase 2 (PRD v1.2).
- 2026-10-24 P-00: scaffold `laravel/react-starter-kit` v1.0.1 (Laravel 12, React 19, Inertia 2, Vite 6, TS). Herd: `herd link` + `herd secure` → https://mini-cms.test (200 setelah `npm run build`), PHP 8.4. Catatan: port Vite 5173 dipakai project lain, Vite akan pindah port otomatis. Menunggu DB `mini_cms` + user dari user (root MySQL berpassword).
- 2026-10-24 P-00 DONE: MySQL `mini_cms` + `mini_cms_test` dibuat; app memakai user dedicated `mini_cms` (bukan root), password acak di `.env` lokal. phpunit.xml → MySQL `mini_cms_test`. `Model::preventLazyLoading` aktif non-prod (AppServiceProvider). Pest 3 + Vitest + CI (MySQL service, composer/npm audit). `npm audit fix` menutup 21 vuln.
  - Deviasi: command seed Admin dipindah ke P-01 (butuh kolom `users.role`).
  - Catatan untuk P-01: starter kit membawa route register publik dan halaman Settings/Appearance bawaan; register harus dihapus (tidak ada registrasi publik), 2FA ditambah (Fortify atau manual).
- 2026-10-24 P-01 DONE: login (generik, lock 5x/10 mnt → 15 mnt + email), 2FA TOTP manual (google2fa + bacon-qr, replay guard, 8 recovery codes hashed, admin wajib), reset password (60 mnt, single-use, cabut sesi, hapus lock), invite (broker `invites` 48 jam), sesi aktif + revoke, idle timeout per role, kick user disabled, user mgmt (invite/role/disable/enable/force-reset, guard admin terakhir + self), audit log (list/filter/CSV, append-only di model), header keamanan dasar (global), `cms:make-admin`. Register publik, verifikasi email, confirm-password, hapus akun dihapus. Argon2id (prod/dev), bcrypt rounds 4 di test. Session encrypted + Secure cookie.
  - Deviasi: (1) kolom `users.failed_logins` tidak dibuat; hitungan gagal pakai RateLimiter window 10 mnt + `locked_until`. (2) 2FA manual, bukan Fortify (starter kit tidak membawa Fortify). (3) TC-F11-03 (UPDATE/DELETE audit_logs ditolak oleh DB): trigger tidak bisa dibuat user non-SUPER saat binlog ON → ditolak oleh model + file `database/hardening/production-grants.sql` (grant per tabel, INSERT/SELECT saja) → MANUAL CHECK di VPS. (4) CSP bernonce tetap di P-06.
  - Dev admin dibuat: anakodok@gmail.com (password acak ditampilkan sekali di chat; 2FA dipaksa saat login pertama).
