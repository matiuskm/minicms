<?php

namespace App\Casts;

use App\Support\HtmlSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Sanitizes on every assignment, so no code path can store raw HTML in the attribute. */
class SanitizedHtml implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return app(HtmlSanitizer::class)->clean($value);
    }
}
