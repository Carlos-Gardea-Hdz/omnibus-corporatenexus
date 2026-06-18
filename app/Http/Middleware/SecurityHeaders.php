<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets baseline security headers on every response (security-2026 §10).
 *
 * A per-response CSP nonce is generated and shared with Inertia/Blade so
 * inline bootstrapping can use 'nonce-…' instead of 'unsafe-inline'. CSP is
 * shipped in Report-Only first; flip to enforcing once violations are clean.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fresh CSPRNG nonce per response — never reused.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set(
            'Content-Security-Policy-Report-Only',
            implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'none'",
                "frame-ancestors 'none'",
            ]),
        );

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }
}
