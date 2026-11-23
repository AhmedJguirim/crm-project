<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    private const BASE_POLICY = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

    /**
     * Adds the browser security headers to every response (clickjacking, content sniffing, referrer leaks, HSTS).
     * A header the response already carries is never overwritten (e.g. the stricter CSP of served storage files).
     * The enforced `Content-Security-Policy` includes a `script-src` policy built on a fresh per-request nonce, shared
     * with Vite, Livewire, Filament, Flux and Horizon, and violations are reported to `csp.report` (SEC-69, enforced
     * since SEC-71). The Laravel debug exception page (debug mode and a 500+ response carrying an exception) gets only
     * the base policy, because its own inline scripts have no nonce.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        Horizon::cspNonce($nonce);

        $response = $next($request);

        $headers = $this->securityHeaders($nonce);

        if ($this->isDebugErrorPage($response)) {
            $headers['Content-Security-Policy'] = self::BASE_POLICY;
        }

        foreach ($headers as $name => $value) {
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
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        return self::BASE_POLICY.'; '.$this->scriptPolicy($nonce).'; report-uri '.route('csp.report', absolute: false);
    }

    /**
     * `'unsafe-eval'` stays because Alpine's standard build and Livewire's `@script` blocks evaluate expressions with
     * `new Function` and Filament does not support Livewire's CSP-safe build. `worker-src blob:` lets Filament's
     * FileUpload (FilePond) start its web workers from blob URLs; `blob:` is not allowed for scripts.
     */
    private function scriptPolicy(string $nonce): string
    {
        $devOrigin = $this->viteDevOrigin();

        return "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".($devOrigin !== null ? ' '.$devOrigin : '')."; worker-src 'self' blob:";
    }

    /**
     * True for Laravel's debug exception page: debug mode, a 500+ status and a response that carries the exception.
     * That page prints inline scripts without a nonce.
     */
    private function isDebugErrorPage(Response $response): bool
    {
        return app()->hasDebugModeEnabled()
            && $response->getStatusCode() >= 500
            && property_exists($response, 'exception')
            && $response->exception !== null;
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
