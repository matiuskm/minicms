<?php

namespace App\Models\Concerns;

use App\Support\PublicCache;

trait BustsPublicCache
{
    public static function bootBustsPublicCache(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            static::$event(fn () => PublicCache::bust());
        }
    }
}
