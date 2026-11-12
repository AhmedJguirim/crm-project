<?php

use App\Support\Security\ProxyTrust;

/**
 * The framework TrustProxies middleware reads the `proxies` key on every request (SEC-30).
 */
return [
    'proxies' => ProxyTrust::proxies(env('TRUSTED_PROXIES')),
];
