<?php

namespace App\Http\Controllers;

use App\Jobs\InsertWeekendHolidaysJob;
use App\Jobs\ProcessTempDataJob;
use App\Jobs\ProcessTempSalaryJob;
use App\Jobs\ProcessRealSalaryJob;
use App\Jobs\ConfirmProvisionalEmployeesJob;
use App\Jobs\ProcessSandwichedHolidaysJob;
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
        } elseif ($queueName === 'processTempSalary_queue') {
            dispatch((new ProcessTempSalaryJob(is_array($payload) ? $payload : ['payload' => $payload]))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } elseif ($queueName === 'processRealSalary_queue') {
            dispatch((new ProcessRealSalaryJob(is_array($payload) ? $payload : ['payload' => $payload]))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } elseif ($queueName === 'confirmProvisionalEmployees_queue') {
            dispatch((new ConfirmProvisionalEmployeesJob(is_array($payload) ? $payload : ['payload' => $payload]))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } elseif ($queueName === 'processSandwichedHolidays_queue') {
            dispatch((new ProcessSandwichedHolidaysJob(is_array($payload) ? $payload : ['payload' => $payload]))
                ->onQueue($queueName)
                ->onConnection('rabbitmq'));
        } else {
            $message = json_encode($payload);
            $queueConnection = app('queue')->connection('rabbitmq');
            $queueConnection->to($queueName)->pushRaw($message);
        }

        return response()->json([
            'queue' => $queueName,
            'payload' => $payload,
            'accepted' => true,
        ]);
    }
}
