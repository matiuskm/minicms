<?php

namespace App\Models;

use App\Models\Concerns\BustsPublicCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BustsPublicCache;

    protected $fillable = ['name', 'slug', 'description', 'parent_id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
