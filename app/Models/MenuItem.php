<?php

namespace App\Models;

use App\Models\Concerns\BustsPublicCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    use BustsPublicCache;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return array{header: list<array{label: string, href: string, external: bool}>, footer: list<array{label: string, href: string, external: bool}>} both menus from one query; dead targets are skipped. */
    public static function resolvedAll(): array
    {
        $menus = ['header' => [], 'footer' => []];

        $items = static::query()->orderBy('sort_order')->orderBy('id')
            ->with(['page:id,slug,status,deleted_at', 'category:id,slug,url_prefix'])->get();

        foreach ($items as $m) {
            $href = match ($m->type) {
                'page' => $m->page && $m->page->status === 'published' ? '/'.$m->page->slug : null,
                'category' => $m->category?->path(),
                default => $m->url,
            };

            if ($href) {
                $menus[$m->location][] = ['label' => $m->label, 'href' => $href, 'external' => str_starts_with($href, 'http')];
            }
        }

        return $menus;
    }
}
