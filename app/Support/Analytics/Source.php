<?php

namespace App\Support\Analytics;

use Illuminate\Support\Str;

/**
 * Works out where a visit came from. Priority: explicit ?utm_source= (links you tag yourself), then the Referer
 * header, then hints in the User-Agent of in-app browsers. Chat apps such as WhatsApp send no Referer and have no
 * in-app browser, so links shared there only show up as "whatsapp" if they carry ?utm_source=whatsapp.
 */
class Source
{
    /** Spelling variants people type in utm_source -> the name we report. */
    private const ALIASES = [
        'ig' => 'instagram', 'insta' => 'instagram', 'fb' => 'facebook', 'wa' => 'whatsapp', 'whatsapp' => 'whatsapp',
        'tw' => 'x', 'twitter' => 'x', 'yt' => 'youtube', 'tg' => 'telegram', 'li' => 'linkedin', 'tt' => 'tiktok',
        'th' => 'threads', 'mail' => 'email', 'newsletter' => 'email', 'ln' => 'line',
    ];

    /** Referrer host (without www.) suffix -> source. */
    private const HOSTS = [
        'instagram.com' => 'instagram', 'facebook.com' => 'facebook', 'fb.me' => 'facebook', 'fb.com' => 'facebook', 'messenger.com' => 'facebook',
        'whatsapp.com' => 'whatsapp', 'wa.me' => 'whatsapp', 't.co' => 'x', 'twitter.com' => 'x', 'x.com' => 'x',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 't.me' => 'telegram', 'telegram.org' => 'telegram', 'telegram.me' => 'telegram',
        'linkedin.com' => 'linkedin', 'lnkd.in' => 'linkedin', 'tiktok.com' => 'tiktok', 'threads.net' => 'threads', 'threads.com' => 'threads',
        'reddit.com' => 'reddit', 'pinterest.com' => 'pinterest', 'line.me' => 'line',
        'bing.com' => 'bing', 'duckduckgo.com' => 'duckduckgo', 'yahoo.com' => 'yahoo', 'ecosia.org' => 'ecosia', 'baidu.com' => 'baidu', 'yandex.com' => 'yandex',
    ];

    /** In-app browsers announce themselves in the User-Agent. */
    private const APPS = [
        '/Instagram/i' => 'instagram', '/FBAN|FBAV|FB_IAB|FBIOS/' => 'facebook', '/musical_ly|BytedanceWebview|TikTok/i' => 'tiktok',
        '/\bLine\//' => 'line', '/Twitter for/i' => 'x',
    ];

    /** @param  array<string, mixed>  $query */
    public static function classify(?string $referer, array $query, string $ownHost, string $userAgent = ''): array
    {
        $campaign = self::clean($query['utm_campaign'] ?? null, 60);
        $medium = self::clean($query['utm_medium'] ?? null, 30);
        $host = self::host($referer);

        if ($utm = self::clean($query['utm_source'] ?? null, 20)) {
            return self::result(self::ALIASES[$utm] ?? $utm, null, $medium, $campaign);
        }

        if ($host !== null && $host === self::strip($ownHost)) {
            return self::result('internal', null, null, null);
        }

        if ($host !== null) {
            foreach (self::HOSTS as $suffix => $name) {
                if ($host === $suffix || str_ends_with($host, ".{$suffix}")) {
                    return self::result($name, null, $medium, $campaign);
                }
            }
            if (preg_match('/(^|\.)google\.[a-z.]+$/', $host)) {
                return self::result('google', null, $medium, $campaign);
            }

            return self::result('referral', Str::limit($host, 80, ''), $medium, $campaign);
        }

        foreach (self::APPS as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return self::result($name, null, $medium, $campaign);
            }
        }

        return self::result('direct', null, $medium, $campaign);
    }

    /** Lower-case, letters/digits/._- and spaces only: values come straight from a URL anyone can craft. */
    public static function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $v = trim(preg_replace('/[^a-z0-9._\- ]+/', '', mb_strtolower($value)));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function host(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? self::strip($host) : null;
    }

    private static function strip(string $host): string
    {
        return preg_replace('/^(www|m|l|lm|mobile)\./', '', mb_strtolower($host));
    }

    /** @return array{source: string, referrer_host: ?string, medium: ?string, campaign: ?string} */
    private static function result(string $source, ?string $host, ?string $medium, ?string $campaign): array
    {
        return ['source' => $source, 'referrer_host' => $host, 'medium' => $medium, 'campaign' => $campaign];
    }
}
