<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // N+1 guard (NFR-04): lazy loading throws outside production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // BR-01: min 12 chars; breached-password check needs outbound HTTP, so prod only.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        // NFR-08: per-IP limits return 429 with Retry-After.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(5)
            ->by($r->session()->get('login.id', 'none').'|'.$r->ip()));
    }
}
