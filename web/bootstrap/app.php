<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
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
        // 'admin' gates the /admin area; a user deactivated mid-session is signed
        // out on their next web request (login already blocks the initial sign-in).
        $middleware->alias(['admin' => EnsureUserIsAdmin::class]);
        $middleware->web(append: [EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ScriptRegistry::find() throws this for an unknown slug (e.g. a stale or
        // crafted route param) — a 404, not a 500.
        $exceptions->render(fn (OutOfBoundsException $e) => abort(404));
    })->create();
