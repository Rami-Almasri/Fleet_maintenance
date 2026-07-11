<?php

namespace App\Helpers;

use App\Exceptions\WorkflowTransitionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ResponseHelper
{
    /**
     * Success Response Helper
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @return \Illuminate\Http\JsonResponse
     */
    public static function SuccessResponse($data, $message = 'Success', $code = 200)
    {
        return response()->json([
            'data' => $data,
            'success' => true,
            'message' => $message
        ], $code);
    }

    /**
     * Failure Response Helper
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @return \Illuminate\Http\JsonResponse
     */
    public static function FailureResponse($data = null, $message = 'Failed', $code = 400)
    {
        return response()->json([
            'data' => $data,
            'success' => false,
            'message' => $message,
        ], $code);
    }

    /**
     * Turn ANY exception into the standard failure envelope with a MEANINGFUL HTTP status code —
     * the single place every API error is shaped. This replaces the old swallow pattern
     * (`catch (\Throwable $e) { return FailureResponse(null, $e->getMessage(), 400); }`) which
     * logged nothing, returned 400 for every failure (a DB timeout looked like a bad request) and
     * leaked raw exception text (SQL, file paths) straight to the client.
     *
     * Mapping:
     *   ValidationException          → 422 (+ field errors in `data`)
     *   WorkflowTransitionException  → 422 (+ its from→to context)
     *   AuthenticationException      → 401
     *   AuthorizationException       → 403
     *   ModelNotFoundException       → 404
     *   any HttpException            → its own status code
     *   anything else                → 500, LOGGED with full context + trace; the raw message is
     *                                  shown only when app.debug is on (never leaked in production).
     *
     * Expected client errors (4xx) are intentional, so they are NOT logged at error level — only
     * genuine server faults (5xx) are, keeping the log a high-signal record of what actually broke.
     */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::FailureResponse(
                $e->errors(),
                $e->validator->errors()->first() ?: 'The given data was invalid.',
                422
            );
        }

        if ($e instanceof WorkflowTransitionException) {
            return self::FailureResponse($e->context ?: null, $e->getMessage(), 422);
        }

        if ($e instanceof AuthenticationException) {
            return self::FailureResponse(null, 'Unauthenticated.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::FailureResponse(null, $e->getMessage() ?: 'This action is unauthorized.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::FailureResponse(null, 'The requested resource was not found.', 404);
        }

        // abort(404/403/409/…) and any framework HttpException keep their own status code.
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            return self::FailureResponse(null, $e->getMessage() ?: 'Request failed.', $status);
        }

        // Genuine server fault → record it with everything needed to debug, then return a safe 500.
        Log::error('Unhandled API exception: ' . $e->getMessage(), [
            'exception' => $e::class,
            'where'     => $e->getFile() . ':' . $e->getLine(),
            'url'       => request()?->fullUrl(),
            'method'    => request()?->method(),
            'user_id'   => optional(request()?->user())->id,
            'trace'     => $e->getTraceAsString(),
        ]);

        return self::FailureResponse(
            null,
            config('app.debug') ? $e->getMessage() : 'Something went wrong on our end. Please try again.',
            500
        );
    }
}
