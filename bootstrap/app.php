<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\SetTenantContextFromRoute;
use App\Http\Middleware\StripNullBytes;
use App\Support\Security\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.context' => SetTenantContextFromRoute::class,
        ]);
        $middleware->append(StripNullBytes::class);
        $middleware->append(AddSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): null {
            SecurityLog::throttledAuthRequest($request);

            return null;
        });
    })->create();
