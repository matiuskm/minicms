<?php

namespace App\Support\Updates;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Looks for new releases and hands update requests to the privileged applier (scripts/apply-update.sh).
 *
 * Why the web app never applies an update itself: PHP-FPM must not be able to write the application code
 * (that is what stops a compromised site from being defaced for good, see docs/DEPLOY.md). So the web side only
 * writes a small request file; a separate process owned by the deploy user downloads the release, checks its
 * Ed25519 signature against the key pinned in the *installed* code, backs up, migrates, switches and verifies.
 */
class Updater
{
    private const CACHE_KEY = 'updates.latest';

    public static function current(): string
    {
        return (string) config('app.version');
    }

    public static function enabled(): bool
    {
        return (bool) config('cms.update.enabled');
    }

    /**
     * The newest published release, or null when it cannot be determined. Cached; a failed lookup is
     * remembered briefly so a down GitHub never slows the admin.
     *
     * @return array{version: string, notes: string, published_at: ?string, url: ?string, signed: bool}|null
     */
    public static function latest(bool $refresh = false): ?array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $result = Cache::get(self::CACHE_KEY);
        if ($result === null) {
            $result = self::fetch();
            Cache::put(self::CACHE_KEY, $result ?: false, $result ? config('cms.update.check_ttl') : 300);
        }

        return $result ?: null;
    }

    /** Newer release already known from the cache; never touches the network (safe on every request). */
    public static function cachedAvailable(): ?array
    {
        $latest = Cache::get(self::CACHE_KEY);

        return is_array($latest) && Version::newer($latest['version'], self::current()) ? $latest : null;
    }

    public static function available(bool $refresh = false): ?array
    {
        $latest = self::latest($refresh);

        return $latest && Version::newer($latest['version'], self::current()) ? $latest : null;
    }

    /** @return array{state: string, step?: string, message?: string, version?: string, from?: string, log?: list<string>, started_at?: ?string, finished_at?: ?string} */
    public static function status(): array
    {
        $file = self::dir().'/status.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && isset($data['state']) ? $data : ['state' => 'idle'];
    }

    public static function busy(): bool
    {
        return in_array(self::status()['state'], ['queued', 'running'], true);
    }

    public static function canInstall(string $version): bool
    {
        return self::enabled() && ! self::busy() && Version::valid($version) && ($a = self::available()) !== null && $a['version'] === $version && $a['signed'];
    }

    /** Writes the request atomically; the applier picks it up within a minute. */
    public static function request(string $version, User $by): void
    {
        File::ensureDirectoryExists(self::dir(), 0775);
        $payload = json_encode(['version' => $version, 'requested_by' => $by->name, 'requested_at' => now()->toIso8601String()], JSON_UNESCAPED_SLASHES);

        $tmp = self::dir().'/.request-'.Str::random(8);
        file_put_contents($tmp, $payload);
        chmod($tmp, 0664);
        rename($tmp, self::dir().'/request.json');

        file_put_contents(self::dir().'/status.json', json_encode([
            'state' => 'queued', 'step' => 'queued', 'message' => 'Waiting for the server to start the update.', 'version' => $version,
            'from' => self::current(), 'log' => [], 'started_at' => now()->toIso8601String(), 'finished_at' => null,
        ]));
        @chmod(self::dir().'/status.json', 0664);
    }

    public static function dir(): string
    {
        return storage_path('app/updates');
    }

    /** @return array{version: string, notes: string, published_at: ?string, url: ?string, signed: bool}|null */
    private static function fetch(): ?array
    {
        try {
            $res = Http::acceptJson()->withUserAgent('minicms-updater')->timeout(8)
                ->get(rtrim(config('cms.update.api'), '/').'/repos/'.config('cms.update.repo').'/releases/latest');
        } catch (\Throwable) {
            return null;
        }

        if (! $res->ok()) {
            return null;
        }

        $data = $res->json();
        $version = Version::fromTag($data['tag_name'] ?? null);
        if (! $version) {
            return null;
        }

        $assets = collect($data['assets'] ?? [])->pluck('name')->all();

        return [
            'version' => $version,
            // Release notes are untrusted text from the network: capped, and only ever shown as plain text.
            'notes' => Str::limit(strip_tags((string) ($data['body'] ?? '')), 8000, '…'),
            'published_at' => is_string($data['published_at'] ?? null) ? $data['published_at'] : null,
            'url' => is_string($data['html_url'] ?? null) && str_starts_with($data['html_url'], 'https://') ? $data['html_url'] : null,
            'signed' => in_array("minicms-v{$version}.tar.gz", $assets, true) && in_array("minicms-v{$version}.tar.gz.sig", $assets, true),
        ];
    }
}
