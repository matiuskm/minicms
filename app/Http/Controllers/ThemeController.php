<?php

namespace App\Http\Controllers;

use App\Support\PublicCache;
use App\Support\ThemeCss;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The public theme stylesheet. The page links it with ?v=<cache version>, so it can be cached forever. */
class ThemeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $css = PublicCache::remember('theme-css', fn () => ThemeCss::current());

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $request->query('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }
}
