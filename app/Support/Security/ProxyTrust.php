<?php

namespace App\Support\Security;

/**
 * Turns the deployment settings that decide which callers and hosts the application believes (SEC-30).
 */
final class ProxyTrust
{
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    private const array TRUST_EVERYBODY = ['*', '**', 'REMOTE_ADDR'];

    /**
     * Turns the TRUSTED_PROXIES value into the proxy list: unset trusts the loopback addresses, empty trusts nobody, and
     * the items that mean "trust whoever calls" (`*`, `**`, `REMOTE_ADDR`) are dropped.
     *
     * @return list<string>
     */
    public static function proxies(?string $value): array
    {
        if ($value === null) {
            return self::LOOPBACK;
        }

        $items = array_map(trim(...), explode(',', $value));

        return array_values(array_filter(
            $items,
            fn (string $item): bool => $item !== '' && ! in_array(strtoupper($item), self::TRUST_EVERYBODY, true),
        ));
    }

    /**
     * The trusted host patterns for the host of APP_URL (exact host, no subdomains), or none when APP_URL has no host.
     *
     * @return list<string>
     */
    public static function hostPatterns(?string $appUrl): array
    {
        $host = parse_url((string) $appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [];
        }

        return ['^'.preg_quote($host).'$'];
    }
}
