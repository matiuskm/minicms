<?php

namespace App\Support;

/**
 * Turns stored (already sanitised) HTML into what visitors receive. Embeds become sandboxed iframes built from
 * fixed provider hosts and a validated id; no author-supplied URL is ever placed in an iframe.
 */
class ContentRenderer
{
    private const HOSTS = [
        'youtube' => 'https://www.youtube-nocookie.com/embed/',
        'vimeo' => 'https://player.vimeo.com/video/',
    ];

    public static function render(string $html): string
    {
        $html = preg_replace_callback(
            '#<div data-embed="(youtube|vimeo)" data-id="([A-Za-z0-9_-]{6,32})"></div>#',
            fn (array $m) => sprintf(
                '<div class="embed"><iframe src="%s%s" title="%s video" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" '
                .'sandbox="allow-scripts allow-same-origin allow-presentation" allowfullscreen></iframe></div>',
                self::HOSTS[$m[1]], $m[2], $m[1],
            ),
            $html,
        );

        return preg_replace('#<img (?![^>]*loading=)#', '<img loading="lazy" decoding="async" ', $html);
    }
}
