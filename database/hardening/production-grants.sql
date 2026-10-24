-- Production DB privileges (NFR-03, PP-F11-02). Run once as a DB admin on the VPS.
-- MySQL cannot exclude one table from a database-level grant, so grant per table
-- and keep audit_logs append-only for the application account.
-- (Triggers were rejected: with binlog on, creating them needs SUPER.)
--
-- Replace `mini_cms` / host / password as needed. Do NOT give the app user
-- ALTER/DROP/CREATE/FILE/SUPER; run migrations with a separate deploy account.

CREATE USER IF NOT EXISTS 'mini_cms_app'@'localhost' IDENTIFIED BY 'CHANGE_ME';

-- Everything the app reads and writes normally. Extend this list as tables are added.
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.users                  TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.password_reset_tokens  TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.sessions               TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.cache                  TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.cache_locks            TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.jobs                   TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.job_batches            TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mini_cms.failed_jobs            TO 'mini_cms_app'@'localhost';
GRANT SELECT, INSERT                 ON mini_cms.audit_logs             TO 'mini_cms_app'@'localhost';
-- Retention purge (12 months) runs from a separate account with DELETE on audit_logs (P-06).
