<?php

namespace App\Helpers;

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
}