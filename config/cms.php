<?php

return [
    // BR-04: idle timeout in minutes per role.
    'idle_minutes' => ['admin' => 30, 'writer' => 120],

    // BR-03: admins must use 2FA. Local development may turn the requirement off with
    // CMS_REQUIRE_ADMIN_2FA=false; it is ignored (always on) in production.
    'require_admin_2fa' => env('CMS_REQUIRE_ADMIN_2FA', true),

    // BR-02: failures allowed within the window before the account locks.
    'lock' => ['max_failures' => 5, 'window_seconds' => 600, 'minutes' => 15],

    // F-05: upload limits in KB (PDF limit becomes an admin setting in F-10).
    'uploads' => [
        'image_max_kb' => 5 * 1024,
        'pdf_max_kb' => 10 * 1024,
        'image_mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'],
        'variants' => ['thumb' => 150, 'medium' => 400, 'large' => 1200],
    ],

    // Display timezone for the public site (storage stays UTC; admin shows browser time).
    'timezone' => 'Asia/Jakarta',

    // Page slugs share the root namespace with application routes (F-07).
    'reserved_slugs' => [
        'admin', 'login', 'logout', 'dashboard', 'settings', 'media', 'files', 'tags', 'content', 'blog',
        'category', 'tag', 'author', 'search', 'sitemap', 'robots', 'rss', 'feed', 'storage', 'build', 'up',
        'forgot-password', 'reset-password', 'invite', 'two-factor-challenge', 'register', 'api', 'preview',
    ],

    // F-08: the only fonts a theme can pick (BR-26). `entry` is a Vite CSS entry that self-hosts the family.
    'fonts' => [
        'system-sans' => ['label' => 'System Sans (default)', 'stack' => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif", 'entry' => null],
        'system-serif' => ['label' => 'System Serif (Georgia)', 'stack' => "Georgia, 'Times New Roman', Times, serif", 'entry' => null],
        'monospace' => ['label' => 'Monospace', 'stack' => "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace", 'entry' => null],
        'open-sans' => ['label' => 'Open Sans', 'stack' => "'Open Sans', ui-sans-serif, system-ui, sans-serif", 'entry' => 'resources/css/fonts/open-sans.css'],
        'roboto' => ['label' => 'Roboto', 'stack' => 'Roboto, ui-sans-serif, system-ui, sans-serif', 'entry' => 'resources/css/fonts/roboto.css'],
        'lato' => ['label' => 'Lato', 'stack' => 'Lato, ui-sans-serif, system-ui, sans-serif', 'entry' => 'resources/css/fonts/lato.css'],
        'merriweather' => ['label' => 'Merriweather', 'stack' => 'Merriweather, Georgia, serif', 'entry' => 'resources/css/fonts/merriweather.css'],
        'source-serif' => ['label' => 'Source Serif', 'stack' => "'Source Serif 4', Georgia, serif", 'entry' => 'resources/css/fonts/source-serif.css'],
    ],

    // Base text size choices as a percentage of the browser default.
    'font_sizes' => ['s' => 93.75, 'm' => 100, 'l' => 112.5],

    'max_custom_palettes' => 20,

    // NFR-13: audit entries older than this are removed by `cms:prune-audit` (needs a DB account with DELETE on audit_logs).
    'audit_retention_months' => 12,

    // F-13: scheduled backups. Point BACKUP_DISK at an off-site disk (e.g. s3) in production.
    'backup' => [
        'disk' => env('BACKUP_DISK', 'backups'),
        'keep' => 14,
        'mysqldump' => env('MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('MYSQL_BINARY', 'mysql'),
    ],

    // F-15: one-click updates. The web app only *requests* an update; a separate privileged process applies it
    // (scripts/apply-update.sh). Off unless the server is set up for it (docs/DEPLOY.md, "One-click updates").
    'update' => [
        'enabled' => env('CMS_UPDATES', false),
        'repo' => env('CMS_UPDATE_REPO', 'matiuskm/minicms'),
        'api' => env('CMS_UPDATE_API', 'https://api.github.com'),
        'public_key_file' => base_path('release-public.key'),
        'check_ttl' => 6 * 3600,
    ],
];
