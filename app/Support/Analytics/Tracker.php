<?php

namespace App\Support\Analytics;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie-less, first-party visit counting. It stores no IP and no user agent; see the page_views migration.
 * Nothing here may ever break a page, so callers wrap it and failures are only reported.
 */
class Tracker
{
    /** Link-preview fetchers, crawlers, uptime monitors and scripted clients are not visitors. */
    private const BOTS = '/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|whatsapp|telegram|discord|slack|skype|embedly|quora|pinterest|'
        .'curl|wget|python|go-http|java\/|okhttp|libwww|httpclient|node-fetch|axios|monitor|uptime|pingdom|gtmetrix|ahrefs|semrush|mj12|dotbot|applebot|yandex|baidu|bing|duckduck|petal|bytespider/i';

    public static function shouldCount(Request $request, Response $response): bool
    {
        if (! Setting::get('analytics_enabled', true) || ! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }
        if (! $request->attributes->has('analytics.key') || $request->user()) {
            return false; // not a public page, or a signed-in member of staff looking at their own site
        }
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return false; // honour "do not track"
        }
        if (str_contains(strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? $request->header('X-Moz'))), 'prefetch')) {
            return false;
        }

        $ua = (string) $request->userAgent();

        return $ua !== '' && ! preg_match(self::BOTS, $ua)
            && ($request->headers->has('X-Inertia') || str_contains((string) $request->header('Accept'), 'text/html'));
    }

    public static function record(Request $request): void
    {
        $visitor = self::visitor($request);

        // A runaway client (or a script that slipped past the bot filter) cannot flood the table.
        $key = 'analytics:'.bin2hex($visitor);
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return;
        }
        RateLimiter::hit($key, 60);

        $source = Source::classify($request->headers->get('referer'), $request->query(), $request->getHost(), (string) $request->userAgent());

        DB::table('page_views')->insert([
            'day' => now()->toDateString(),
            'at' => now(),
            'visitor' => $visitor,
            'page_key' => mb_substr((string) $request->attributes->get('analytics.key'), 0, 60),
            ...$source,
        ]);
    }

    /** First 8 bytes of HMAC(day-key, ip|user-agent). The key changes daily, so the hash cannot be linked across days. */
    public static function visitor(Request $request): string
    {
        $dayKey = hash_hmac('sha256', 'analytics|'.now()->toDateString(), (string) config('app.key'), true);

        return substr(hash_hmac('sha256', $request->ip().'|'.$request->userAgent(), $dayKey, true), 0, 8);
    }

    public static function prune(): int
    {
        $cutoff = now()->subDays(max(30, (int) Setting::get('analytics_retention_days', 90)))->toDateString();
        $deleted = 0;

        do {
            $n = DB::table('page_views')->where('day', '<', $cutoff)->limit(10000)->delete();
            $deleted += $n;
        } while ($n === 10000);

        return $deleted;
    }
}
