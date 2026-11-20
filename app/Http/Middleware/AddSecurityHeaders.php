<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Adds the browser security headers to every response (clickjacking, content sniffing, referrer leaks, HSTS).
     * A header the response already carries is never overwritten (e.g. the stricter CSP of served storage files).
     * Also sends a report-only `script-src` policy built on a fresh per-request nonce, shared with Vite, Livewire,
     * Filament, Flux and Horizon, and reported to `csp.report` (SEC-69). Nothing is blocked by it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        Horizon::cspNonce($nonce);

        $response = $next($request);

        foreach ($this->securityHeaders($nonce) as $name => $value) {
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
    private function securityHeaders(string $nonce): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            'Content-Security-Policy-Report-Only' => $this->reportOnlyPolicy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }

    /**
     * `'unsafe-eval'` stays because Alpine's standard build evaluates expressions with `new Function`; without it every
     * page would file reports that hide the useful ones.
     */
    private function reportOnlyPolicy(string $nonce): string
    {
        $devOrigin = $this->viteDevOrigin();

        return "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".($devOrigin !== null ? ' '.$devOrigin : '').'; report-uri '.route('csp.report', absolute: false);
    }

    /**
     * The origin (scheme, host, port) of the running Vite dev server, read from its hot file; null when it is not running
     * or the file holds no usable URL.
     */
    private function viteDevOrigin(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $parts = parse_url(rtrim((string) rescue(fn () => file_get_contents(Vite::hotFile()), '', report: false)));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || preg_match('/^https?\z/', $parts['scheme']) !== 1 || preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)\z/i', $parts['host']) !== 1) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
