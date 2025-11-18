<?php

namespace App\Http\Middleware;

use Closure;

class ApiTimerMiddleware
{
    public function handle($request, Closure $next)
    {

        $request->attributes->set('api_start_time', microtime(true));

        $response = $next($request);

        // Only JSON responses should be modified
        if (method_exists($response, 'getContent') && str_contains($response->headers->get('Content-Type'), 'application/json')) {
            $data = json_decode($response->getContent(), true);

            // Avoid null or malformed responses
            if (is_array($data)) {
                $start = $request->attributes->get('api_start_time');
                $duration = round(microtime(true) - $start, 6) . 's';

                // Inject response_time
                $data['meta']['response_time'] = $duration;

                return response()->json($data, $response->getStatusCode());
            }
        }

        return $response;
    }
}
