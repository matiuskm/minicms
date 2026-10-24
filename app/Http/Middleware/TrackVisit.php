<?php

namespace App\Http\Middleware;

use App\Support\Analytics\Tracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts public page views after the response has been sent, so tracking never slows or breaks a page. */
class TrackVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        defer(function () use ($request, $response) {
            try {
                if (Tracker::shouldCount($request, $response)) {
                    Tracker::record($request);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $response;
    }
}
