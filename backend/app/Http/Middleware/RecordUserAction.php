<?php

namespace App\Http\Middleware;

use App\Services\UserActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records WHAT an employee did, not just where he went.
 *
 * Runs in the global `api` group and watches every mutating request
 * (POST / PUT / PATCH / DELETE). When one succeeds for an authenticated user it
 * appends a single row to `user_activity_events` — the same append-only log the
 * page views live in — so the Workforce drawer can show one honest timeline:
 * "09:14 opened Workflow · 09:16 added a Maintenance · 09:41 deleted Vehicle #12".
 *
 * Deliberate boundaries:
 *  • only 2xx responses are logged — a rejected or failed attempt is not an action;
 *  • no request body is stored (payloads carry customer data and secrets); the
 *    endpoint, method, record id and timestamp are the evidence;
 *  • IGNORED covers self-reporting/noise endpoints that would drown the trail.
 *
 * Logging never breaks the request: any failure here is swallowed.
 */
class RecordUserAction
{
    /** Endpoints whose writes are noise or are already logged as their own event type. */
    private const IGNORED = [
        'auth/activity',    // presence heartbeat — logged as page/login rows instead
        'auth/login',       // logged as a `login` event
        'auth/logout',      // logged as a `logout` event
    ];

    /** Path prefixes whose writes are read-side machinery, not employee decisions. */
    private const IGNORED_PREFIXES = [
        'notifications',    // marking notifications read/seen
    ];

    public function __construct(private readonly UserActivityService $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldRecord($request, $response)) {
                // Resolved AFTER the route ran, so the sanctum token is available
                // even on routes that authenticate downstream of this middleware.
                if ($user = auth('sanctum')->user()) {
                    $this->activity->recordAction($user, $request, $response->getStatusCode());
                }
            }
        } catch (\Throwable $e) {
            // An audit row is never worth failing a real operation over.
            report($e);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        $path = ltrim(preg_replace('#^api/#', '', $request->path()), '/');

        foreach (self::IGNORED as $ignored) {
            if ($path === $ignored) {
                return false;
            }
        }
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }
}
