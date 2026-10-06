<?php

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
        // Port of src/proxy.ts + the session/role guards of the original app.
        $middleware->alias([
            'auth' => \App\Http\Middleware\RequireLogin::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'setup.state' => \App\Http\Middleware\EnsureSetupState::class,
        ]);

        // The offline queue replays mutations with fetch(); it can't follow a
        // redirect to the login page, so it must get a 401/419 it understands.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
