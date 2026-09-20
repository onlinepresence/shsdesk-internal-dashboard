<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Global abuse ceiling for the unauthenticated enroll endpoint.
// Deferred past boot because facades are unavailable at require time.
// Per-IP protection comes from the inline throttle on the route.
$app->booting(function (): void {
    RateLimiter::for('enroll-global', fn (): Limit => Limit::perMinute(600));
    RateLimiter::for('leads-global', fn (): Limit => Limit::perMinute(300));
});

return $app;
