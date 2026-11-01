<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP + logging for the old ERP sync jobs. The using job defines
 * RESOURCE (e.g. 'apiLoans'), LOG_CHANNEL and logSubject().
 */
trait OldErpApi
{
    /** view; null when the old ERP has no such record. */
    private function get(array $query): ?array
    {
        return $this->call('GET', 'view', $query);
    }

    /** null when the old ERP refused it (logged). */
    private function post(string $action, array $query, array $body): ?array
    {
        return $this->call('POST', $action, $query, $body);
    }

    private function call(string $method, string $action, array $query, array $body = []): ?array
    {
        $url = rtrim(env('OLD_ERP_API_URL', 'http://30.30.28.73:8080/erp/erp/api'), '/')
            . '/' . static::RESOURCE . '/' . $action . ($query ? '?' . http_build_query($query) : '');

        $request = Http::timeout(20)->acceptJson()->withHeaders(['X-API-KEY' => (string) env('OLD_ERP_API_KEY')]);
        try {
            $response = $method === 'GET' ? $request->get($url) : $request->asJson()->post($url, $body);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->log('error', "{$action} failed, old ERP unreachable, will retry", ['error' => $e->getMessage()]);
            throw $e;
        }

        $context = [
            'action' => $action, 'query' => $query, 'body' => $body,
            'http' => $response->status(), 'response' => $response->json() ?? substr($response->body(), 0, 1000),
        ];

        if ($response->successful()) {
            if ($method === 'POST') {
                $this->log('info', "{$action} ok", $context);
            }
            return (array) ($response->json('data') ?? []);
        }
        if ($method === 'GET' && $response->status() === 404) {
            return null;
        }
        // a 4xx is the old ERP saying no (422 validation, 409 conflict / loan stopped, ...): retrying
        // won't change it; only a wrong key (401), a timeout (408), throttling (429) and 5xx are retried
        if ($response->clientError() && !in_array($response->status(), [401, 408, 429], true)) {
            $this->log('error', "{$action} refused by old ERP", $context);
            return null;
        }

        $this->log('error', "{$action} failed, will retry", $context);
        throw new \RuntimeException("Old ERP {$action} for {$this->logSubject()}: HTTP {$response->status()}");
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel(static::LOG_CHANNEL)->{$level}("{$this->logSubject()}: {$message}", $context);
    }
}
