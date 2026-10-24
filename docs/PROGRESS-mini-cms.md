# Progress — Mini CMS
PRD: docs/PRD-mini-cms-2026-10-24.md  |  Started: 2026-10-24
Project dir: repo root (this repo)

| Phase | Status | Passing points | Evidence | Commit |
|---|---|---|---|---|
| P-00 Project setup | done (awaiting user review) | app boots, test runner runs, migrations apply: PASS | https://mini-cms.test → 200; Pest 27 passed; Vitest 1 passed; `migrate:status` 3 Ran on MySQL; `composer audit` + `npm audit` 0 vulns; `build` + `build:ssr` OK | not committed (no git repo) |
| P-01 Identitas & jejak | done (awaiting user review) | PP-F01-01..03, PP-F02-01..02, PP-F11-01 PASS; PP-F11-02 PASS (model) + MANUAL CHECK (DB grants) | Pest 48 passed (225 assertions); Vitest 1; tsc + eslint clean; build + build:ssr OK; composer/npm audit 0; Herd smoke: headers + 404 /register + 302 /admin/users | not committed (no git) |
| P-02 Taksonomi, media, editor | done (awaiting review) | PP-F03-01..03, PP-F04-01..02, PP-F05-01..02 PASS (F05-02 server config = MANUAL CHECK); TC-F04-02/05 deferred to P-03 | Pest 158 passed (1636 assertions); Vitest 18; tsc, eslint, prettier, pint clean; build + build:ssr OK; composer/npm audit 0 | PR phase/p-02-taxonomy-media-editor |
| P-03 Konten | done (awaiting review) | PP-F06-01..03, PP-F07-01..02 PASS; deferred P-02 items closed (TC-F04-02/05, FR-22/23, FR-27, BR-18) | Pest 199 passed (1940 assertions); Vitest 24; tsc, eslint, prettier, pint clean; build + build:ssr OK; composer/npm audit 0 | PR phase/p-03-posts-pages |
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
- 2026-10-24 P-02 DONE (branch `phase/p-02-taxonomy-media-editor`, release 0.3.0): shell admin direstyle sesuai referensi; Categories/Tags; Media (finfo, re-encode via Intervention Image v4 GD, varian thumb/medium/large, PDF di disk privat + `/files/{uuid}`); `HtmlSanitizer` (HTMLPurifier, 40 payload XSS + idempotensi) + cast `SanitizedHtml` + `/content/preview`; TipTap v3 editor (figure/figcaption, embed placeholder, source mode admin, word count).
  - Deviasi: lihat PRD A-12 (embed disimpan sebagai placeholder; FR-22/23, BR-18, FR-27, TC-F04-02/05 pindah ke P-03; `media.uuid` ditambah; tanpa search di top bar).
  - Belum diverifikasi visual di browser: editor baru dipakai di form Post/Page (P-03); sekarang tervalidasi lewat Vitest (render, toolbar, source toggle, word count) + build. MANUAL CHECK TC-F05-05 (eksekusi PHP di folder upload) dan PHP `upload_max_filesize` 2M di Herd → naikkan ke 12M (README).
- 2026-10-24 P-03 DONE (branch `phase/p-03-posts-pages`, release 0.4.0, stacked on P-02): Posts + Pages + revisions + redirects + scheduler + tag merge/counters + media in-use guard. Lihat PRD A-13 untuk keputusan (satu request save+transisi, 422 untuk konflik versi di Save dan 409 di autosave, waktu UTC, batas gambar `/storage/uploads/`).
  - Belum diverifikasi visual di browser (halaman React baru hanya lolos tsc/lint/build/test komponen; tidak ada uji browser otomatis). MANUAL CHECK: alur penulis → review → publish di browser, tombol Save dropdown, autosave 30 dtk, tag input, revisions dialog.
