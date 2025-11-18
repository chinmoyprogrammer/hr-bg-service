<?php

use App\Helpers\ApiResponse;

if (!function_exists('api_success')) {
    function api_success($data = null, $message = 'Success', $code = 200, $meta = [],$requestId = null) {
        return ApiResponse::success($data, $message, $code, $meta,$requestId );
    }
}

if (!function_exists('api_error')) {
    function api_error($message = 'Error', $code = 500, $errors = [], $meta = [],$requestId = null) {
        return ApiResponse::error($message, $code, $errors, $meta,$requestId );
    }
}

if (!function_exists('formatBDNumber')) 
{
    function formatBDNumber($n) {
        $dec = strpos($n, '.') !== false ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') : $n;
        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', preg_replace('/\d(?=(\d{3})+\.)/', '$0,', $dec));

    }
    
}

if (!function_exists('safeCollection')) 
{
    function safeCollection($model, string $relation)
    {
        return $model->relationLoaded($relation) ? ($model->{$relation} ?? collect()) : collect();
    }
}


if (!function_exists('collectionHasNested')) 
{
    function collectionHasNested($collection, callable $checker): bool
    {
        return $collection->isNotEmpty() && $collection->some($checker);
    }
}
