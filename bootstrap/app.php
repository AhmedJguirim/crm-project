<?php

use App\Http\Middleware\SetTenantContextFromRoute;
use App\Http\Middleware\StripNullBytes;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
