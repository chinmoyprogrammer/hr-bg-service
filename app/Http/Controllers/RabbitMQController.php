<?php

namespace App\Http\Controllers;

use App\Jobs\RabbitMQJob;
use Illuminate\Http\Request;

class RabbitMQController extends Controller
{
    public function publishMessage(Request $request)
    {
        $queueName = 'notification';
        $message = $request->input('message', 'Hello from hr-admin-backend!');
        // Accept multiple shapes: user_ids (array), user_id (single), comma-separated string
        $rawUserIds = $request->input('user_ids');
        if ($rawUserIds === null) {
            $rawUserIds = $request->input('user_id');
        }
        if ($rawUserIds === null) {
            $rawUserIds = $request->input('users');
        }

        $userIds = [];
        if (is_array($rawUserIds)) {
            $userIds = array_values($rawUserIds);
        } elseif (is_string($rawUserIds)) {
            // handle comma-separated or single string
            $parts = array_map('trim', explode(',', $rawUserIds));
            $userIds = array_values(array_filter($parts, function($v){ return $v !== ''; }));
        } elseif (is_numeric($rawUserIds)) {
            $userIds = [strval($rawUserIds)];
        }

        $payload = [
            'message' => $message,
            'user_ids' => array_values($userIds),
        ];

        dispatch(new RabbitMQJob($payload, $queueName));

        return response()->json([
            'status' => $payload,
            'queue' => $queueName,
            'payload' => $payload,
        ]);
    }
}