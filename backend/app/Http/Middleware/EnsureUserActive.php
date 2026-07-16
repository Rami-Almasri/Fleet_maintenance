<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks any authenticated request made by a suspended account.
 *
 * Login itself also rejects suspended users (AuthController::login), but that
 * only guards *new* logins. A user suspended AFTER they logged in still holds a
 * valid Sanctum token; this middleware closes that gap by re-checking status on
 * every authenticated API request. Unauthenticated requests (login, signup,
 * health checks) carry no user() and pass straight through.
 */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve via the sanctum guard explicitly: this middleware runs in the
        // global `api` group, BEFORE the per-route `auth:sanctum` middleware has
        // populated $request->user(), so we must trigger token resolution here.
        // On unauthenticated routes (login/signup) this simply returns null.
        $user = auth('sanctum')->user();

        if ($user && $user->status === 'suspended') {
            // Revoke the token being used so the suspension sticks immediately.
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'msg' => 'This account has been suspended. Contact an administrator.',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }
}
