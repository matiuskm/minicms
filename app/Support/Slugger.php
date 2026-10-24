<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Slugger
{
    /** Unique slug in $table; non-Latin text that transliterates to nothing gets a random one. */
    public static function make(string $text, string $table, ?int $ignoreId = null, string $column = 'slug'): string
    {
        $base = Str::slug($text) ?: 'n-'.Str::lower(Str::random(6));
        $slug = $base;
        $i = 2;

        while (DB::table($table)->where($column, $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
