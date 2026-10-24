-- Production MySQL accounts (NFR-03, PP-F11-02). Run once as a DB admin on the VPS. See docs/DEPLOY.md.
--
-- Two accounts:
--   cms_migrator : used only while deploying (php artisan migrate --force). Schema changes allowed.
--   cms_app      : used by the running site. Data access only, and audit_logs is append-only.
-- MySQL cannot exclude one table from a database-level grant, so the app account is granted table by table.
-- (Triggers were rejected: with binary logging on, creating them needs SUPER.)
-- Replace the passwords. Re-run the GRANT block after a release that adds tables.

CREATE DATABASE IF NOT EXISTS mini_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER IF NOT EXISTS 'cms_migrator'@'localhost' IDENTIFIED BY 'CHANGE_ME_MIGRATOR';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER ON mini_cms.* TO 'cms_migrator'@'localhost';

CREATE USER IF NOT EXISTS 'cms_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_APP';

-- Normal application tables.
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.users                 TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.password_reset_tokens TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.sessions              TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.cache                 TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.cache_locks           TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.jobs                  TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.job_batches           TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.failed_jobs           TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.categories            TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.tags                  TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.media                 TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.posts                 TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.post_tag              TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.pages                 TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.revisions             TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.redirects             TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.settings              TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.menu_items            TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.palettes              TO 'cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.known_devices         TO 'cms_app'@'localhost';

-- Append-only: the application can write and read the audit trail but never alter or delete it.
GRANT SELECT, INSERT ON mini_cms.audit_logs TO 'cms_app'@'localhost';

-- Backups run as the app user (mysqldump needs SELECT and, for --single-transaction, nothing more).
-- Retention purging of old audit rows (12 months) is done by an admin with the migrator account.
FLUSH PRIVILEGES;
