<?php

namespace App\Http\Controllers;

use App\Jobs\RabbitMQJob;
use App\Jobs\InsertWeekendHolidaysJob;
use App\Jobs\ProcessTempDataJob;
use Illuminate\Http\Request;

class RabbitMQController extends Controller
{
    public function publishMessage(Request $request)
    {
        $queueName = $request->input('queue') ?? $request->input('queue_name');
        if (!is_string($queueName) || trim($queueName) === '') {
            return response()->json(['error' => 'queue is required'], 422);
        }

        $payload = $request->input('payload');
        if ($payload === null) {
            $body = $request->all();
            unset($body['queue'], $body['queue_name']);
            $payload = $body;
        }

        if ($queueName === 'insertWeekendHolidays_queue') {
            dispatch((new InsertWeekendHolidaysJob(is_array($payload) ? $payload : []))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } elseif ($queueName === 'processTempData_queue') {
            dispatch((new ProcessTempDataJob(is_array($payload) ? $payload : []))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } else {
            dispatch(new RabbitMQJob($payload, $queueName));
        }

        return response()->json([
            'queue' => $queueName,
            'payload' => $payload,
            'accepted' => true,
        ]);
    }
}
