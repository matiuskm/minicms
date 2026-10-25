<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The home page as an ordered list of sections (F-16), stored as JSON in the `home_sections` setting.
 * The set of section types is closed and every field is validated and re-built from known keys,
 * so the stored layout can never carry markup or links the site did not intend.
 */
class HomePage
{
    public const TYPES = ['featured', 'image', 'split', 'slider', 'rich_text', 'post_grid', 'cta', 'blog'];

    public const MAX_SECTIONS = 12;

    /** Posts the featured section consumes: one feature plus the "Latest post" list. */
    public const FEATURED_POSTS = 5;

    /** http(s) or a site path only: javascript: and friends are rejected. */
    public const LINK = '#^(https?://\S+|/(?!/)\S*)$#';

    /** @return list<array<string, mixed>> the layout the site shipped with: feature block, then the blog. */
    public static function default(): array
    {
        return [
            ['id' => 'featured', 'type' => 'featured', 'enabled' => true, 'post_id' => null],
            ['id' => 'blog', 'type' => 'blog', 'enabled' => true, 'title' => ''],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function layout(): array
    {
        $stored = Setting::get('home_sections');

        return is_array($stored) && $stored ? $stored : self::default();
    }

    /** @return array<string, mixed> validation rules for a submitted list, built per section type */
    public static function rules(array $sections): array
    {
        $rules = [
            'sections' => ['required', 'array', 'min:1', 'max:'.self::MAX_SECTIONS],
            'sections.*' => ['array'],
        ];

        $image = fn () => ['required', 'integer', Rule::exists('media', 'id')->where(fn ($q) => $q->where('mime', 'like', 'image/%'))];
        $button = fn (string $p, bool $required) => [
            "{$p}.button_label" => [$required ? 'required' : 'nullable', 'string', 'max:40', 'required_with:'."{$p}.button_url"],
            "{$p}.button_url" => [$required ? 'required' : 'nullable', 'string', 'max:255', 'regex:'.self::LINK, 'required_with:'."{$p}.button_label"],
        ];

        foreach (array_keys($sections) as $i) {
            $p = "sections.{$i}";
            $rules += [
                "{$p}.id" => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,24}$/'],
                "{$p}.type" => ['required', Rule::in(self::TYPES)],
                "{$p}.enabled" => ['required', 'boolean'],
            ];

            $rules += match ($sections[$i]['type'] ?? null) {
                'featured' => ["{$p}.post_id" => ['nullable', 'integer', 'exists:posts,id']],
                'image' => [
                    "{$p}.media_id" => $image(),
                    "{$p}.title" => ['nullable', 'string', 'max:120'],
                    "{$p}.text" => ['nullable', 'string', 'max:400'],
                    "{$p}.align" => ['required', Rule::in(['left', 'center', 'right'])],
                    ...$button($p, false),
                ],
                'split' => [
                    "{$p}.media_id" => $image(),
                    "{$p}.title" => ['nullable', 'string', 'max:160'],
                    "{$p}.body" => ['nullable', 'string', 'max:10000'],
                    "{$p}.image_side" => ['required', Rule::in(['left', 'right'])],
                    ...$button($p, false),
                ],
                'slider' => [
                    "{$p}.autoplay" => ['required', 'boolean'],
                    "{$p}.slides" => ['required', 'array', 'min:2', 'max:8'],
                    "{$p}.slides.*" => ['array'],
                    ...collect(range(0, 7))->filter(fn ($n) => isset($sections[$i]['slides'][$n]))->flatMap(fn ($n) => [
                        "{$p}.slides.{$n}.media_id" => $image(),
                        "{$p}.slides.{$n}.title" => ['nullable', 'string', 'max:120'],
                        "{$p}.slides.{$n}.text" => ['nullable', 'string', 'max:300'],
                        ...$button("{$p}.slides.{$n}", false),
                    ])->all(),
                ],
                'rich_text' => ["{$p}.title" => ['nullable', 'string', 'max:120'], "{$p}.body" => ['required', 'string', 'max:20000']],
                'post_grid' => [
                    "{$p}.title" => ['nullable', 'string', 'max:120'],
                    "{$p}.source" => ['required', Rule::in(['latest', 'category', 'tag'])],
                    "{$p}.category_id" => ['nullable', 'required_if:'."{$p}.source,category", 'integer', 'exists:categories,id'],
                    "{$p}.tag_id" => ['nullable', 'required_if:'."{$p}.source,tag", 'integer', 'exists:tags,id'],
                    "{$p}.count" => ['required', 'integer', 'min:3', 'max:12'],
                ],
                'cta' => [
                    "{$p}.title" => ['required', 'string', 'max:120'],
                    "{$p}.text" => ['nullable', 'string', 'max:300'],
                    ...$button($p, true),
                ],
                'blog' => ["{$p}.title" => ['nullable', 'string', 'max:120']],
                default => [],
            };
        }

        return $rules;
    }

    /**
     * Rebuilds validated input from known keys only. Rich text goes through the HTML sanitizer.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $sections): array
    {
        $text = fn (array $s, string $k) => trim((string) ($s[$k] ?? ''));
        $button = fn (array $s) => ['button_label' => $text($s, 'button_label'), 'button_url' => $text($s, 'button_url')];

        return collect($sections)->map(function (array $s) use ($text, $button) {
            $base = ['id' => $s['id'], 'type' => $s['type'], 'enabled' => (bool) $s['enabled']];

            return $base + match ($s['type']) {
                'featured' => ['post_id' => $s['post_id'] ?? null],
                'image' => ['media_id' => (int) $s['media_id'], 'title' => $text($s, 'title'), 'text' => $text($s, 'text'), 'align' => $s['align'], ...$button($s)],
                'split' => ['media_id' => (int) $s['media_id'], 'title' => $text($s, 'title'), 'body' => (new HtmlSanitizer)->clean((string) ($s['body'] ?? '')), 'image_side' => $s['image_side'], ...$button($s)],
                'slider' => [
                    'autoplay' => (bool) $s['autoplay'],
                    'slides' => collect($s['slides'])->map(fn (array $x) => ['media_id' => (int) $x['media_id'], 'title' => $text($x, 'title'), 'text' => $text($x, 'text'), ...$button($x)])->values()->all(),
                ],
                'rich_text' => ['title' => $text($s, 'title'), 'body' => (new HtmlSanitizer)->clean($s['body'])],
                'post_grid' => ['title' => $text($s, 'title'), 'source' => $s['source'], 'category_id' => $s['source'] === 'category' ? (int) $s['category_id'] : null, 'tag_id' => $s['source'] === 'tag' ? (int) $s['tag_id'] : null, 'count' => (int) $s['count']],
                'cta' => ['title' => $text($s, 'title'), 'text' => $text($s, 'text'), ...$button($s)],
                'blog' => ['title' => $text($s, 'title')],
            };
        })->values()->all();
    }

    /** Layout-level rules that need the whole list: one blog section, unique ids, no empty rich text. */
    public static function problems(array $sections): array
    {
        $errors = [];
        $types = array_column($sections, 'type');

        if ((array_count_values($types)['blog'] ?? 0) !== 1) {
            $errors['sections'] = 'The page needs exactly one Blog section: it carries the post list and the page numbers.';
        }
        if (count(array_unique(array_column($sections, 'id'))) !== count($sections)) {
            $errors['sections'] = 'Section ids must be unique.';
        }
        foreach ($sections as $i => $s) {
            if ($s['type'] === 'split' && $s['title'] === '' && HtmlSanitizer::isBlank($s['body'])) {
                $errors["sections.{$i}.title"] = 'Add a heading or some text next to the image.';
            }
            if ($s['type'] === 'rich_text' && HtmlSanitizer::isBlank($s['body'])) {
                $errors["sections.{$i}.body"] = 'The text is empty.';
            }
        }

        return $errors;
    }

    /**
     * What the public page receives: only enabled sections, images and posts resolved in a constant number of queries.
     * Page 2 and up show just the blog list.
     *
     * @return array{sections: list<array<string, mixed>>, pager: array<string, mixed>}|null null when the page number is out of range
     */
    public static function build(int $page, callable $published): ?array
    {
        $layout = collect(self::layout())->where('enabled', true)->values();
        $pinned = $layout->firstWhere('type', 'featured');

        // Posts the feature block uses, newest first; the blog list skips exactly these.
        $heroPosts = $pinned ? self::heroPosts($pinned['post_id'] ?? null, $published) : collect();
        $heroIds = $heroPosts->pluck('id')->all();
        $blog = $layout->firstWhere('type', 'blog');
        $per = PublicData::perPage();

        $total = $blog ? Post::published()->whereNotIn('id', $heroIds)->count() : 0;
        $last = max(1, (int) ceil($total / $per));
        if ($page > $last) {
            return null;
        }

        $media = self::media($layout);
        $cards = $blog ? $published()->whereNotIn('id', $heroIds)->skip(($page - 1) * $per)->take($per)->get()->map(fn (Post $p) => PublicData::card($p)) : collect();
        $hero = $heroPosts->map(fn (Post $p) => PublicData::card($p));

        $sections = $layout->filter(fn ($s) => $page === 1 || $s['type'] === 'blog')->map(fn (array $s) => ['id' => $s['id'], 'type' => $s['type']] + match ($s['type']) {
            'featured' => ['featured' => $hero->first(), 'latest' => $hero->slice(1)->values()],
            'image' => self::image($s, $media),
            'split' => ['title' => $s['title'], 'html' => ContentRenderer::render($s['body']), 'image' => self::image($s, $media)['image'], 'side' => $s['image_side'], 'button' => self::button($s)],
            'slider' => ['autoplay' => $s['autoplay'], 'slides' => collect($s['slides'])->map(fn ($x) => self::image($x, $media))->all()],
            'rich_text' => ['title' => $s['title'], 'html' => ContentRenderer::render($s['body'])],
            'post_grid' => ['title' => $s['title'], 'posts' => self::grid($s, $published)],
            'cta' => ['title' => $s['title'], 'text' => $s['text'], 'button' => self::button($s)],
            'blog' => ['title' => $s['title'], 'posts' => $cards],
        })->values()->all();

        return ['sections' => $sections, 'pager' => PublicData::pager([$page, $last], '/')];
    }

    /** @return Collection<int, Post> the pinned post (when it is live) first, then the newest others, FEATURED_POSTS in all */
    private static function heroPosts(?int $pinnedId, callable $published): Collection
    {
        $pinned = $pinnedId ? $published()->whereKey($pinnedId)->first() : null;
        $newest = $published()->when($pinned, fn ($q) => $q->whereKeyNot($pinned->id))->limit(self::FEATURED_POSTS - ($pinned ? 1 : 0))->get();

        return $pinned ? $newest->prepend($pinned) : $newest;
    }

    /** @return list<int> every media item the sections point at (a delete of these must be refused) */
    public static function mediaIds(array $sections): array
    {
        return collect($sections)->flatMap(fn ($s) => match ($s['type']) {
            'image', 'split' => [$s['media_id']],
            'slider' => array_column($s['slides'], 'media_id'),
            default => [],
        })->unique()->values()->all();
    }

    /** One query for every image any section needs. */
    private static function media(Collection $layout): Collection
    {
        $ids = self::mediaIds($layout->all());

        return $ids ? Media::whereIn('id', $ids)->get()->keyBy('id') : collect();
    }

    /** @return array<string, mixed> */
    private static function image(array $s, $media): array
    {
        $m = $media[$s['media_id']] ?? null;

        return [
            'image' => $m ? ['large' => $m->url('large'), 'medium' => $m->url('medium'), 'alt' => $m->alt_text] : null,
            'title' => $s['title'] ?? '', 'text' => $s['text'] ?? '', 'button' => self::button($s), 'align' => $s['align'] ?? 'left',
        ];
    }

    /** @return array{label: string, url: string, external: bool}|null */
    private static function button(array $s): ?array
    {
        return ($s['button_label'] ?? '') && ($s['button_url'] ?? '')
            ? ['label' => $s['button_label'], 'url' => $s['button_url'], 'external' => str_starts_with($s['button_url'], 'http')]
            : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function grid(array $s, callable $published)
    {
        return $published()
            ->when($s['source'] === 'category', fn ($q) => $q->where('category_id', $s['category_id']))
            ->when($s['source'] === 'tag', fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $s['tag_id'])))
            ->limit($s['count'])->get()->map(fn (Post $p) => PublicData::card($p));
    }
}
