<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Laravel auto-discovers every handle*/__invoke method in app/Listeners
    // and registers it *in addition to* the manual Event::listen() calls in
    // AppServiceProvider::boot() below, so every listener there was firing
    // twice (confirmed via Event::getListeners() returning 2 for e.g.
    // ContractClientApproved). All listeners are registered manually, so
    // discovery is pure duplication — disabling it removes the duplicate
    // registration without removing any listener.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\Localization::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\UpdateLastSeen::class,
        ]);
        $middleware->alias([
            'auth.any' => \App\Http\Middleware\AuthAny::class,
            'scope.workspace' => \App\Http\Middleware\ScopeWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
