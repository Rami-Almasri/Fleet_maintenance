<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Spatie permission middleware aliases, used as `permission:...`,
        // `role:...`, `role_or_permission:...` in routes/api.php.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Reject requests from suspended accounts on every API route (even with an
        // already-issued token). Resolves the user via the sanctum guard itself,
        // so ordering relative to per-route `auth:sanctum` doesn't matter.
        $middleware->api(append: [
            \App\Http\Middleware\EnsureUserActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Any exception that bubbles uncaught out of an API route is shaped through the SAME unified
        // path the controllers use (meaningful status code + the standard JSON envelope; 5xx logged
        // with context). One source of truth for error responses — and a safety net for any handler
        // that doesn't (or no longer needs to) wrap itself in try/catch.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // FormRequest validation is thrown BEFORE the controller body (uncaught), so it reaches
            // here. Leave it to Laravel's native {message, errors} renderer — the frontend forms read
            // that shape. Everything else on an API route flows through the unified envelope.
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return \App\Helpers\ResponseHelper::fromException($e);
            }

            return null; // non-API requests keep Laravel's default rendering
        });
    })->create();
