<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache for public page data (A-07). Every key carries a version; any content, menu, setting or theme change
 * bumps it, which invalidates everything at once without needing cache tags (works on the database driver).
 */
class PublicCache
{
    public const TTL = 600; // seconds

    public static function version(): int
    {
        return (int) Cache::rememberForever('public.version', fn () => 1);
    }

    public static function remember(string $key, callable $callback, int $ttl = self::TTL): mixed
    {
        return Cache::remember('public.'.self::version().'.'.$key, $ttl, $callback);
    }

    public static function bust(): void
    {
        Cache::forever('public.version', self::version() + 1);
    }
}
