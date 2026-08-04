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
        // RecordUserAction appends one audit row per SUCCESSFUL write (insert /
        // change / delete) to the same log the page views live in, so the
        // Workforce drawer can show what an employee did, not just where he went.
        $middleware->api(append: [
            \App\Http\Middleware\EnsureUserActive::class,
            \App\Http\Middleware\RecordUserAction::class,
        ]);

        // There is no server-rendered login page — the React SPA owns sign-in — so the named
        // route `login` does not exist. Laravel still installs a default guest redirect of
        // `fn () => route('login')` whenever web routes are registered, and that closure runs
        // INSIDE Authenticate::unauthenticated(), i.e. before the exception reaches the
        // handler below. It threw RouteNotFoundException, which replaced the
        // AuthenticationException and surfaced as a logged 500 instead of a 401. Redirect
        // guests nowhere: the AuthenticationException then survives intact for the renderer.
        $middleware->redirectGuestsTo(fn () => null);
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

            // Answer every guest request with the 401 envelope, whatever the path or headers.
            // Laravel's fallback for this exception is `redirect()->guest(… ?? route('login'))`,
            // and this application has no `login` route to build — reaching that fallback is a
            // guaranteed 500. There is no HTML area to redirect to, so JSON is the honest answer.
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return \App\Helpers\ResponseHelper::fromException($e);
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return \App\Helpers\ResponseHelper::fromException($e);
            }

            return null; // non-API requests keep Laravel's default rendering
        });
    })->create();
