<?php

namespace App\Support;

use App\Support\Purifier\EmbedIdAttrDef;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

/**
 * Server-side allowlist sanitizer (BR-13..16). Every write path for rich content goes through here;
 * the editor's own filtering is a convenience, never a control.
 *
 * Videos are stored as <div data-embed="youtube|vimeo" data-id="..."> and turned into a sandboxed
 * iframe at render time, so no URL from the author ever reaches an iframe src.
 */
class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $cache = storage_path('framework/cache/purifier');
        File::ensureDirectoryExists($cache);

        $c = HTMLPurifier_Config::createDefault();
        $c->set('Core.Encoding', 'UTF-8');
        $c->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $c->set('Cache.SerializerPath', $cache);
        $c->set('HTML.DefinitionID', 'mini-cms');
        $c->set('HTML.DefinitionRev', 1);

        $c->set('HTML.Allowed', implode(',', [
            'p[style]', 'br', 'h2[style]', 'h3[style]', 'h4[style]',
            'strong', 'em', 'u', 's', 'a[href|title|target]',
            'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'hr',
            'table', 'thead', 'tbody', 'tr', 'th[colspan|rowspan|style]', 'td[colspan|rowspan|style]',
            'img[src|alt|width|height]', 'figure', 'figcaption',
            'div[data-embed|data-id]',
        ]));
        $c->set('CSS.AllowedProperties', ['text-align']);

        // BR-15: only these schemes; external images/resources are dropped (same-origin paths only).
        $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $c->set('URI.DisableExternalResources', true);
        $c->set('HTML.Nofollow', true);
        $c->set('HTML.TargetNoopener', true);
        $c->set('HTML.TargetNoreferrer', true);
        $c->set('Attr.AllowedFrameTargets', ['_blank']);

        if ($def = $c->maybeGetRawHTMLDefinition()) {
            $def->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
            $def->addAttribute('div', 'data-embed', 'Enum#youtube,vimeo');
            $def->addAttribute('div', 'data-id', new EmbedIdAttrDef);
        }

        $this->purifier = new HTMLPurifier($c);
    }

    /** Reading time in minutes at 200 words/min; CJK characters count as one word each (matches the editor). */
    public static function readingMinutes(string $html): int
    {
        $text = html_entity_decode(strip_tags(str_replace('>', '> ', $html)));
        $cjk = '[\x{3040}-\x{30ff}\x{3400}-\x{9fff}\x{f900}-\x{faff}\x{ac00}-\x{d7af}]';
        $cjkCount = preg_match_all("/{$cjk}/u", $text);
        $words = count(preg_split('/\s+/u', trim(preg_replace("/{$cjk}/u", ' ', $text)), -1, PREG_SPLIT_NO_EMPTY)) + $cjkCount;

        return max(1, min(255, (int) ceil($words / 200)));
    }

    /** True when sanitized HTML has no text and no media (an empty editor yields "<p></p>"). */
    public static function isBlank(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html))) === ''
            && ! preg_match('/<(img|figure|hr|table)\b|data-embed=/i', $html);
    }

    public function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $clean = $this->purifier->purify($html);

        // Images may only point at our own uploads; anything else (relative guesses, other paths) is dropped.
        $clean = preg_replace_callback('#<img\b[^>]*>#i', function (array $m) {
            return preg_match('#\bsrc="/storage/uploads/(?!.*\.\.)[A-Za-z0-9/_.-]+"#', $m[0]) ? $m[0] : '';
        }, $clean);

        // An embed without both attributes is meaningless; drop it.
        return preg_replace('#<div(?![^>]*data-embed=)[^>]*>\s*</div>#', '', $clean);
    }
}
