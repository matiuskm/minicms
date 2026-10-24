<?php

/**
 * Writes the update progress file the admin page reads. Standalone (no framework).
 *
 *   php scripts/update-status.php <status.json> <state> <step> <message> [version] [from]
 * state: queued | running | success | failed. The log keeps the last 200 lines.
 */
if ($argc < 5) {
    fwrite(STDERR, "usage: php scripts/update-status.php <file> <state> <step> <message> [version] [from]\n");
    exit(2);
}

[, $file, $state, $step, $message] = $argv;
$old = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
$old = is_array($old) ? $old : [];
$now = gmdate('c');

$log = array_slice([...($old['log'] ?? []), "[{$now}] {$step}: {$message}"], -200);

$new = [
    'state' => $state,
    'step' => $step,
    'message' => mb_substr($message, 0, 300),
    'version' => $argv[5] ?? $old['version'] ?? null,
    'from' => $argv[6] ?? $old['from'] ?? null,
    'log' => $log,
    'started_at' => $old['started_at'] ?? $now,
    'finished_at' => in_array($state, ['success', 'failed'], true) ? $now : null,
];

$tmp = $file.'.tmp';
file_put_contents($tmp, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
chmod($tmp, 0664);
rename($tmp, $file);
