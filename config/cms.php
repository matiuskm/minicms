<?php

return [
    // BR-04: idle timeout in minutes per role.
    'idle_minutes' => ['admin' => 30, 'writer' => 120],

    // BR-02: failures allowed within the window before the account locks.
    'lock' => ['max_failures' => 5, 'window_seconds' => 600, 'minutes' => 15],

    // F-05: upload limits in KB (PDF limit becomes an admin setting in F-10).
    'uploads' => [
        'image_max_kb' => 5 * 1024,
        'pdf_max_kb' => 10 * 1024,
        'image_mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'],
        'variants' => ['thumb' => 150, 'medium' => 400, 'large' => 1200],
    ],
];
