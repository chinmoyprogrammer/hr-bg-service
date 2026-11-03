<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * /api/jobs/* auth. A bearer token is checked with the auth service, the same endpoint the
 * api-gateway uses (so a caller inside the Docker network can't just send an X-User-Id).
 * There are no roles in this ERP, so access is by user id lists:
 *   JOBS_VIEWER_USER_IDS  read runs, logs, summary (admins are viewers too)
 *   JOBS_ADMIN_USER_IDS   also retry / replay / run-now
 * X-Internal-Token = JOBS_INTERNAL_TOKEN is full access, for scripts and ops.
 * Usage: 'jobs.auth' (viewer) or 'jobs.auth:admin'.
 */
class JobsAuth
{
    public function handle(Request $request, Closure $next, string $level = 'viewer')
    {
        $internal = (string) env('JOBS_INTERNAL_TOKEN', '');
        if ($internal !== '' && hash_equals($internal, (string) $request->header('X-Internal-Token'))) {
            $request->attributes->set('jobs_user', 'internal');
            return $next($request);
        }

        $token = (string) $request->bearerToken();
        if ($token === '') {
            return response()->json(['message' => 'Authentication required'], 401);
        }
        try {
            $userId = self::userId($token);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Auth service unavailable'], 503);
        }
        if (!$userId) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $admins = self::ids('JOBS_ADMIN_USER_IDS');
        $allowed = $level === 'admin' ? $admins : [...$admins, ...self::ids('JOBS_VIEWER_USER_IDS')];
        if (!in_array((string) $userId, $allowed, true)) {
            return response()->json(['message' => $level === 'admin' ? 'Job admin access required' : 'Job dashboard access required'], 403);
        }

        $request->attributes->set('jobs_user', (string) $userId);
        return $next($request);
    }

    /** User id for a valid token, null for an invalid one; cached 30 s so the dashboard's polling doesn't hit auth each time. */
    private static function userId(string $token): ?string
    {
        $key = 'jobs_auth:' . hash('sha256', $token);
        if (($cached = Cache::get($key)) !== null) {
            return $cached ?: null;
        }
        $response = Http::timeout(5)->withToken($token)
            ->post(env('AUTH_VALIDATE_URL', 'http://auth:3001/api/auth/validate-token'));
        if ($response->serverError()) {
            $response->throw();
        }
        $id = $response->successful() && $response->json('isValid') ? (string) $response->json('user.id') : '';
        Cache::put($key, $id, 30);  // '' = invalid, also cached so a bad token can't hammer auth
        return $id ?: null;
    }

    private static function ids(string $env): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) env($env, '')))));
    }
}
