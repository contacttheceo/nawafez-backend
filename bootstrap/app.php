<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register custom middleware aliases
        $middleware->alias([
            'admin'    => \App\Http\Middleware\EnsureAdmin::class,
            'verified' => \App\Http\Middleware\EnsureEmailVerified::class,
        ]);

        // Trust all proxies (needed for Freehostia shared hosting)
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for API routes on auth failures
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*')
        );
    })
    ->create();
