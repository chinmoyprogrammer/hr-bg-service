<?php

namespace App\Helpers;

class ApiResponse
{
    public static function success($data = null, string $message = 'Success', int $code = 200, array $meta = [],$requestId = null)
    {
        return response()->json([
            'status'  => 'success',
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'meta'    => array_merge([
                'timestamp' => date('Y-m-d H:i:s P'),
                'requestId' => $requestId ?? (request()->header('X-Request-ID') ?? uniqid()),
            ], $meta),
            'errors'  => null,
            'errorCode' => null
        ], $code);
    }

    public static function error(string $message = 'Error', int $code = 500, array $errors = [], array $meta = [],$requestId = null)
    {
        return response()->json([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
            'data'    => null,
            'meta'    => array_merge([
                'timestamp' => date('Y-m-d H:i:s P'),
                'requestId' => $requestId ?? (request()->header('X-Request-ID') ?? uniqid()),
            ], $meta),
            'errors'  => $errors ?: null,
            'errorCode' => null
        ], $code);
    }
}
