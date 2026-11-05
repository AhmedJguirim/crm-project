<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Adds the browser security headers to every response (clickjacking, content sniffing, referrer leaks, HSTS).
     * A header the response already carries is never overwritten (e.g. the stricter CSP of served storage files).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->securityHeaders() as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * HSTS is sent only in production on an https APP_URL (not based on the request scheme: without trusted proxies
     * a TLS-terminating proxy makes every request look like http).
     *
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }
}
