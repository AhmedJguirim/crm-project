<?php

namespace App\Http\Controllers;

use App\Support\Security\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the browser's Content-Security-Policy violation reports (`report-uri` format, SEC-69) and writes one
 * `csp.violation` line to the security log. It logs only the violated directive (a short lower-case word), the blocked
 * and source origins (scheme and host, never a path or query), the line number and the name of the route the page
 * belongs to. It never logs a full URL, a script sample, the original policy, the referrer or any other key: URLs can
 * hold invitation or export tokens and the sample can hold page content. The whole body is untrusted; every answer is
 * an empty response (204, 400 or 413), never an error page.
 */
class CspReportController extends Controller
{
    public const int MAX_BODY_BYTES = 16384;

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->noContent(413);
        }

        $decoded = json_decode($body, true, 8);
        $report = is_array($decoded) ? ($decoded['csp-report'] ?? null) : null;

        if (! is_array($report)) {
            return response()->noContent(400);
        }

        $line = $report['line-number'] ?? null;

        SecurityLog::info('csp.violation', null, [
            'directive' => $this->directive($report),
            'blocked' => $this->reduceSource($report['blocked-uri'] ?? null),
            'source' => $this->reduceSource($report['source-file'] ?? null),
            'line' => is_int($line) && $line >= 0 && $line <= 10_000_000 ? $line : null,
            'page_route' => $this->routeNameFor($report['document-uri'] ?? null),
        ]);

        return response()->noContent();
    }

    /**
     * @param  array<array-key, mixed>  $report
     */
    private function directive(array $report): string
    {
        $effective = $report['effective-directive'] ?? null;
        $violated = $report['violated-directive'] ?? null;

        $candidate = is_string($effective) && $effective !== ''
            ? $effective
            : (is_string($violated) ? strtok($violated, ' ') : null);

        return is_string($candidate) && preg_match('/^[a-z-]{1,40}\z/', $candidate) === 1 ? $candidate : 'other';
    }

    private function reduceSource(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (in_array($value, ['inline', 'eval', 'wasm-eval'], true)) {
            return $value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return 'other';
        }

        if (! isset($parts['host'])) {
            return mb_substr($parts['scheme'], 0, 200);
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return mb_substr($origin, 0, 200);
    }

    private function routeNameFor(mixed $documentUri): ?string
    {
        if (! is_string($documentUri)) {
            return null;
        }

        $path = parse_url($documentUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $name = rescue(fn (): ?string => app('router')->getRoutes()->match(Request::create($path, 'GET'))->getName(), null, report: false);

        return is_string($name) ? mb_substr($name, 0, 200) : null;
    }
}
