<?php

namespace App\Support;

use App\Models\Palette;
use App\Models\Setting;

/**
 * Builds the public theme stylesheet from validated data only (BR-24..26): colours must match a strict hex pattern,
 * fonts and sizes must be keys of the allowlists. Anything else is skipped, so no stored value can inject CSS.
 */
class ThemeCss
{
    /** Palette token -> CSS variable used by the public site. */
    private const VARS = [
        'background' => '--site-bg', 'surface' => '--site-surface', 'text' => '--site-text', 'muted' => '--site-muted',
        'primary' => '--site-primary', 'accent' => '--site-soft', 'border' => '--site-border', 'link' => '--site-link',
    ];

    /** @param  array<string, string>  $tokens */
    public static function build(array $tokens, string $heading, string $body, string $size): string
    {
        $fonts = config('cms.fonts');
        $decl = [];

        foreach (self::VARS as $token => $var) {
            $value = $tokens[$token] ?? null;
            if (is_string($value) && preg_match(Palette::HEX, $value)) {
                $decl[] = "{$var}:".strtolower($value);
            }
        }

        // Text on a primary-coloured button: whichever of white / near-black reads better.
        if (isset($tokens['primary']) && preg_match(Palette::HEX, $tokens['primary'])) {
            $decl[] = '--site-on-primary:'.(self::contrast($tokens['primary'], '#ffffff') >= self::contrast($tokens['primary'], '#111111') ? '#ffffff' : '#111111');
        }

        $decl[] = '--site-font-heading:'.($fonts[$heading]['stack'] ?? $fonts['system-sans']['stack']);
        $decl[] = '--site-font-body:'.($fonts[$body]['stack'] ?? $fonts['system-sans']['stack']);

        $pct = config('cms.font_sizes')[$size] ?? 100;

        // `html .site` outranks the plain `.site` defaults even when Vite re-inserts app.css after this sheet.
        return 'html .site{'.implode(';', $decl).'}'."\n".'html{font-size:'.$pct.'%}'."\n";
    }

    /** CSS for whatever is currently configured. */
    public static function current(): string
    {
        return self::build(
            Palette::active()->tokens,
            (string) Setting::get('font_heading', 'system-sans'),
            (string) Setting::get('font_body', 'system-sans'),
            (string) Setting::get('font_size', 'm'),
        );
    }

    /** Vite CSS entries for the self-hosted families in use (system fonts need none). */
    public static function fontEntries(): array
    {
        $fonts = config('cms.fonts');

        return collect([Setting::get('font_heading'), Setting::get('font_body')])
            ->map(fn ($k) => $fonts[$k]['entry'] ?? null)->filter()->unique()->values()->all();
    }

    /** WCAG contrast ratio between two hex colours (alpha ignored). */
    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channel = fn (int $i) => (function (float $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        })((float) hexdec(substr($hex, $i, 2)));

        return 0.2126 * $channel(0) + 0.7152 * $channel(2) + 0.0722 * $channel(4);
    }
}
