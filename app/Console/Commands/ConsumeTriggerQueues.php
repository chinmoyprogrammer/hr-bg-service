<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTempDataJob;
use App\Jobs\EmployeeDeactivation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Str;

class ConsumeTriggerQueues extends Command
{
    protected $signature = 'rabbitmq:consume-triggers {--queues=}';

    protected $description = 'Consume trigger queues with JSON payloads and dispatch internal jobs.';

    public function handle(): int
    {
        $queues = $this->option('queues') ?: env('HR_BG_TRIGGER_QUEUES', '');
        
        $queueList = array_values(array_filter(array_map('trim', explode(',', (string) $queues))));

        if (!$queueList) {
            $this->error('No queues provided. Use --queues=queue1,queue2 or set HR_BG_TRIGGER_QUEUES.');
            return 1;
        }

        $host = env('RABBITMQ_HOST', '127.0.0.1');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $pass = env('RABBITMQ_PASSWORD', 'guest');
        $vhost = env('RABBITMQ_VHOST', '/');

        $connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $channel = $connection->channel();

        foreach ($queueList as $queueName) {
            $channel->queue_declare($queueName, false, true, false, false);
        }

        $channel->basic_qos(null, 1, null);

        foreach ($queueList as $queueName) {
            $channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                function ($msg) use ($queueName) {
                    
                    $body = $msg->getBody();
                    try {
                        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                        
                    } catch (\Throwable $e) {
                        Log::error('Trigger message is not valid JSON', [
                            'queue' => $queueName,
                            'body' => $body,
                            'error' => $e->getMessage(),
                        ]);
                        $msg->ack();
                        return;
                    }
                    Log::info('Consuming trigger message from queue final: ' . $queueName);
                    try {
                        $this->dispatchFromTriggerQueue($queueName, is_array($payload) ? $payload : []);
                        $msg->ack();
                    } catch (\Throwable $e) {
                        Log::error('Trigger consumer failed', [
                            'queue' => $queueName,
                            'payload' => $payload,
                            'error' => $e->getMessage(),
                        ]);
                        $msg->reject(false);
                    }
                }
            );
        }

        $this->info('Consuming trigger queues: ' . implode(', ', $queueList));

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return 0;
    }

    private function dispatchFromTriggerQueue(string $queueName, array $payload): void
    {
        Log::info('Consuming trigger message', ['queue' => $queueName, 'payload' => $payload]);
        $suffix = '_trigger_queue';
        if (!\Illuminate\Support\Str::endsWith($queueName, $suffix)) {
            Log::warning('No trigger handler registered for queue', [
                'queue' => $queueName,
                'payload' => $payload,
            ]);
            return;
        }

        $jobMapJson = (string) env('HR_BG_TRIGGER_JOB_MAP', '');
        $jobMap = [];
        if ($jobMapJson !== '') {
            try {
                $decoded = json_decode($jobMapJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $jobMap = $decoded;
                }
            } catch (\Throwable $e) {
                Log::warning('Invalid HR_BG_TRIGGER_JOB_MAP JSON', ['error' => $e->getMessage()]);
            }
        }

        $baseName = substr($queueName, 0, -strlen($suffix));
        $targetQueue = $baseName . '_queue';

        $studlyBaseName = \Illuminate\Support\Str::studly($baseName);
        $jobClass = $jobMap[$queueName] ?? null;

        if (!is_string($jobClass) || !class_exists($jobClass)) {
            $jobClass = 'App\\Jobs\\' . $studlyBaseName . 'Job';
            if (!class_exists($jobClass)) {
                $jobClass = 'App\\Jobs\\' . $studlyBaseName;
            }
        }

        if (!class_exists($jobClass)) {
            Log::warning('No trigger handler registered for queue', [
                'queue' => $queueName,
                'payload' => $payload,
                'resolved_job' => $jobClass,
            ]);
            return;
        }

        try {
            $job = new $jobClass($payload);
        } catch (\Throwable $e) {
            Log::error('Trigger handler resolved but could not be instantiated', [
                'queue' => $queueName,
                'resolved_job' => $jobClass,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        dispatch($job->onQueue($targetQueue)->onConnection('rabbitmq'));
    }
}
