<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BackupFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Database dump plus uploaded files, written to the backup disk with a retention limit (FR-73). */
class Backup
{
    /** @return array{db: string, media: ?string, bytes: int} */
    public static function run(): array
    {
        $disk = Storage::disk(config('cms.backup.disk'));
        $stamp = now()->format('Ymd-His');
        $tmp = sys_get_temp_dir()."/cms-backup-{$stamp}-".bin2hex(random_bytes(4));
        mkdir($tmp, 0700);

        try {
            $db = "backup-{$stamp}-db.sql.gz";
            self::dumpDatabase("{$tmp}/{$db}");
            $disk->put($db, fopen("{$tmp}/{$db}", 'r'));
            $bytes = filesize("{$tmp}/{$db}");

            $media = self::archiveMedia("{$tmp}/media.tar.gz") ? "backup-{$stamp}-media.tar.gz" : null;
            if ($media) {
                $disk->put($media, fopen("{$tmp}/media.tar.gz", 'r'));
                $bytes += filesize("{$tmp}/media.tar.gz");
            }

            self::prune($disk);
            Setting::put(['backup_last' => ['at' => now()->toIso8601String(), 'ok' => true, 'files' => array_values(array_filter([$db, $media])), 'bytes' => $bytes, 'error' => null]]);
            AuditLog::record('backup.completed', null, ['db' => $db, 'media' => $media, 'bytes' => $bytes]);

            return ['db' => $db, 'media' => $media, 'bytes' => $bytes];
        } catch (\Throwable $e) {
            Setting::put(['backup_last' => ['at' => now()->toIso8601String(), 'ok' => false, 'files' => [], 'bytes' => 0, 'error' => mb_substr($e->getMessage(), 0, 300)]]);
            AuditLog::record('backup.failed', null, ['error' => mb_substr($e->getMessage(), 0, 300)]);
            Notification::send(User::where('role', 'admin')->where('status', 'active')->get(), new BackupFailed($e->getMessage()));

            throw $e;
        } finally {
            foreach (glob("{$tmp}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /** Restores a database dump (and optionally the media archive) from the backup disk or an absolute path. */
    public static function restore(string $dbFile, ?string $mediaFile = null): void
    {
        $db = self::localCopy($dbFile);

        try {
            $c = config('database.connections.'.config('database.default'));
            $mysql = new Process([config('cms.backup.mysql'), '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $c['database']], null, ['MYSQL_PWD' => (string) $c['password']]);
            $mysql->setTimeout(1800);
            $mysql->setInput(self::gunzipStream($db));
            $mysql->mustRun();
        } finally {
            if ($db !== $dbFile && ! is_file($dbFile)) {
                @unlink($db);
            }
        }

        if ($mediaFile) {
            $media = self::localCopy($mediaFile);
            (new Process(['tar', '-xzf', $media, '-C', storage_path('app')]))->setTimeout(1800)->mustRun();
            if ($media !== $mediaFile && ! is_file($mediaFile)) {
                @unlink($media);
            }
        }
    }

    /** @return list<string> backup sets on the disk, newest first, as their DB file names */
    public static function sets(): array
    {
        $files = collect(Storage::disk(config('cms.backup.disk'))->files())->filter(fn ($f) => str_ends_with($f, '-db.sql.gz'))->sort()->reverse()->values();

        return $files->all();
    }

    private static function dumpDatabase(string $target): void
    {
        $c = config('database.connections.'.config('database.default'));
        $dump = new Process([
            config('cms.backup.mysqldump'), '--single-transaction', '--quick', '--no-tablespaces', '--default-character-set=utf8mb4',
            '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $c['database'],
        ], null, ['MYSQL_PWD' => (string) $c['password']]);
        $dump->setTimeout(1800);

        $gz = gzopen($target, 'wb6');
        $dump->run(function ($type, $buffer) use ($gz) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            }
        });
        gzclose($gz);

        if (! $dump->isSuccessful() || filesize($target) < 100) {
            throw new RuntimeException('mysqldump failed: '.trim($dump->getErrorOutput() ?: 'no output'));
        }
    }

    private static function archiveMedia(string $target): bool
    {
        $paths = collect(['public/uploads', 'private/files'])->filter(fn ($p) => is_dir(storage_path("app/{$p}")))->values();

        if ($paths->isEmpty()) {
            return false;
        }

        (new Process(['tar', '-czf', $target, '-C', storage_path('app'), ...$paths->all()]))->setTimeout(1800)->mustRun();

        return true;
    }

    private static function prune($disk): void
    {
        $keep = config('cms.backup.keep');
        $stamps = collect($disk->files())->map(fn ($f) => preg_match('/^backup-(\d{8}-\d{6})-/', $f, $m) ? $m[1] : null)->filter()->unique()->sort()->reverse()->values();

        foreach ($stamps->slice($keep) as $old) {
            $disk->delete(["backup-{$old}-db.sql.gz", "backup-{$old}-media.tar.gz"]);
        }
    }

    /** A readable local path for a file name on the backup disk, or the path itself if it exists. */
    private static function localCopy(string $file): string
    {
        if (is_file($file)) {
            return $file;
        }

        $disk = Storage::disk(config('cms.backup.disk'));
        throw_unless($disk->exists($file), RuntimeException::class, "Backup file not found: {$file}");

        $tmp = tempnam(sys_get_temp_dir(), 'cms-restore-');
        file_put_contents($tmp, $disk->readStream($file));

        return $tmp;
    }

    private static function gunzipStream(string $path): \Generator
    {
        $gz = gzopen($path, 'rb');
        while (! gzeof($gz)) {
            yield gzread($gz, 1 << 20);
        }
        gzclose($gz);
    }
}
