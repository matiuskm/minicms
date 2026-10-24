<?php

use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Maintenance;
use App\Http\Middleware\SecurityHeaders;
use App\Support\PublicData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['role' => EnsureRole::class]);
        // Global so 404/500 responses (which skip the web group) get headers too.
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            EnforceSessionPolicy::class,
            Maintenance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Visitors get a plain branded page, never a stack trace (BR-30). 500s keep the debug page while APP_DEBUG is on.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $generic = in_array($status, [403, 404, 503], true) || ($status === 500 && ! config('app.debug'));

            if (! $generic || $request->expectsJson() || $request->is('up')) {
                return $response;
            }

            return Inertia::render('error', [
                'status' => $status,
                'message' => $status === 503 && $e->getMessage() === 'maintenance' ? 'maintenance' : null,
                'site' => rescue(fn () => PublicData::site(), null, false),
                'theme' => rescue(fn () => PublicData::theme(), null, false),
            ])->toResponse($request)->setStatusCode($status)->withHeaders($status === 503 ? ['Retry-After' => 3600] : []);
        });
    })->create();
