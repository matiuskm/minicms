<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** Point $from at $to, and re-point any older redirects that ended at $from (no chains). */
    public static function record(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        static::where('to_path', $from)->update(['to_path' => $to]);
        static::where('from_path', $to)->delete(); // moving back onto an old slug
        static::updateOrCreate(['from_path' => $from], ['to_path' => $to, 'status_code' => 301]);
    }
}
