<?php

namespace App\Models;

use App\Models\Concerns\BustsPublicCache;
use Illuminate\Database\Eloquent\Model;

class Palette extends Model
{
    use BustsPublicCache;

    public const TOKENS = ['background', 'surface', 'text', 'muted', 'primary', 'accent', 'border', 'link'];

    /** 6 or 8 digit hex only (BR-24): free-form CSS never reaches the stylesheet. */
    public const HEX = '/^#(?:[0-9a-f]{6}|[0-9a-f]{8})$/i';

    protected $guarded = ['id', 'is_preset', 'is_active'];

    protected function casts(): array
    {
        return ['tokens' => 'array', 'is_preset' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function active(): self
    {
        return static::where('is_active', true)->first() ?? static::where('name', 'Light Classic')->firstOrFail();
    }
}
