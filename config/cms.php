<?php

return [
    // BR-04: idle timeout in minutes per role.
    'idle_minutes' => ['admin' => 30, 'writer' => 120],

    // BR-02: failures allowed within the window before the account locks.
    'lock' => ['max_failures' => 5, 'window_seconds' => 600, 'minutes' => 15],
];
