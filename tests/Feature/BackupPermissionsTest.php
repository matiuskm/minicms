<?php

use Illuminate\Support\Facades\Storage;

// The updater runs as another user (group member) than the site, so backups must be group-writable.
test('TC-F13-21 the backup disk creates group-writable directories and files', function () {
    $disk = Storage::disk('backups');
    $name = 'perm-test-'.bin2hex(random_bytes(4)).'/x.txt';
    $old = umask(0002); // what php-fpm and the updater cron run with on installer-made servers
    $disk->put($name, 'x');
    umask($old);

    try {
        expect(fileperms($disk->path($name)) & 0777)->toBe(0660)
            ->and(fileperms(dirname($disk->path($name))) & 0777)->toBe(0770);
    } finally {
        $disk->deleteDirectory(dirname($name));
    }
});
