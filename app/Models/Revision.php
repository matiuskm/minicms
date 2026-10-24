<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
