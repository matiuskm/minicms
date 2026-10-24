<?php

namespace App\Models;

use App\Models\Concerns\BustsPublicCache;
use App\Support\PublicCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Setting extends Model
{
    use BustsPublicCache;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public const DEFAULTS = [
        'site_name' => null, // falls back to APP_NAME
        'tagline' => '',
        'logo_media_id' => null,
        'favicon_media_id' => null,
        'content_language' => 'en',
        'posts_per_page' => 6,
        'cta_label' => '',
        'cta_url' => '',
        'pdf_max_mb' => 10,
        'mail_from_name' => '',
        'mail_from_address' => '',
        'maintenance_mode' => false,
        'font_heading' => 'system-sans',
        'font_body' => 'system-sans',
        'font_size' => 'm',
        'analytics_enabled' => true,
        'analytics_retention_days' => 90,
        'lockdown' => false,
        'integrity_last' => null,
        'backup_last' => null,
    ];

    /** @return array<string, mixed> every setting, defaults filled in; one query, cached until a setting changes. */
    public static function everything(): array
    {
        try {
            return Cache::rememberForever('settings.all', fn () => [...self::DEFAULTS, ...static::query()->pluck('value', 'key')->all()]);
        } catch (QueryException) {
            return self::DEFAULTS; // database or cache table not migrated yet (fresh install, CI)
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::everything()[$key] ?? $default;
    }

    /** @param  array<string, mixed>  $values */
    public static function put(array $values): void
    {
        // Written as raw JSON so "null" (an unset logo, say) is a value, not a SQL NULL.
        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        }

        Cache::forget('settings.all');
        PublicCache::bust();
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('settings.all'));
    }
}
