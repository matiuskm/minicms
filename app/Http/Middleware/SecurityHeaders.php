<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers and a nonce-based Content-Security-Policy (FR-66, NFR-06).
 * Scripts only run from our own origin or with the per-response nonce; inline event handlers and eval are not allowed.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The nonce must exist before the view renders so Vite and Ziggy can stamp their tags with it.
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);

        // Responses that set their own policy (PDF downloads: "sandbox") keep it.
        if (! $response->headers->has('Content-Security-Policy') && ! file_exists(public_path('hot'))) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce, $request->isSecure()));
        }

        // Back-office and account screens must never be indexed.
        if ($request->is('admin', 'admin/*', 'settings', 'settings/*', 'dashboard', 'media', 'login', 'two-factor-challenge', 'forgot-password', 'reset-password/*', 'invite/*', 'content/*', 'tags/*', 'search')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce, bool $secure): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Stylesheets from us (or nonce-stamped); style="" attributes are needed by the UI libraries.
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            'frame-src https://www.youtube-nocookie.com https://player.vimeo.com',
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        if ($secure) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
