<?php

namespace App\Jobs;

use App\Jobs\InsertWeekendHolidaysJob;
use App\Jobs\ProcessRealSalaryJob;
use App\Jobs\ProcessTempDataJob;
use App\Jobs\ProcessTempSalaryJob;
use App\Jobs\RecalculateAttendanceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RabbitMQJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $queueName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data, $queueName = null)
    {
        $this->data = $data;
        $this->queueName = $queueName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $message = is_string($this->data) ? $this->data : json_encode($this->data);

        try {
            $queueConnection = app('queue')->connection('rabbitmq');
            $currentQueue = method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null;
            $targetQueue = (is_string($this->queueName) && trim($this->queueName) !== '') ? $this->queueName : null;

            if ($targetQueue !== null && $currentQueue !== null && $targetQueue === $currentQueue) {
                if ($targetQueue === 'processTempSalary_queue') {
                    (new ProcessTempSalaryJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'processTempData_queue') {
                    (new ProcessTempDataJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'insertWeekendHolidays_queue') {
                    (new InsertWeekendHolidaysJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'processRealSalary_queue') {
                    (new ProcessRealSalaryJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'recalculateAttendance_queue') {
                    (new RecalculateAttendanceJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'createDeviceUserByEmpCode_queue') {
                    (new CreateDeviceUserByEmpCode(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }
                if ($targetQueue === 'processManualData_queue') {
                    (new ProcessManualDataJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                                       return;
                }
                if ($targetQueue === 'recalculateSelectedAttendanceData_queue') {
                    (new RecalculateSelectedAttendanceDataJob(is_array($this->data) ? $this->data : ['payload' => $this->data]))->handle();
                    return;
                }

                Log::info('RabbitMQJob skipped re-publish due to same queue', ['queue' => $targetQueue, 'payload' => $this->data]);
                return;
            }

            if ($targetQueue) {
                $queueConnection->to($targetQueue)->pushRaw($message);
            } else {
                $queueConnection->pushRaw($message);
            }
            Log::info('RabbitMQJob published', [
                'queue' => $targetQueue ?: '(default)',
                'payload' => $this->data,
            ]);

            if (is_array($this->data) && isset($this->data['reply_to'])) {
                $replyQueue = (string) $this->data['reply_to'];
                $correlationId = isset($this->data['correlation_id']) ? (string) $this->data['correlation_id'] : null;
                $result = [
                    'status' => 'ok',
                    'queue' => $targetQueue ?: '(default)',
                    'correlation_id' => $correlationId,
                    'payload' => $this->data,
                ];
                $queueConnection->to($replyQueue)->pushRaw(json_encode($result));
                Log::info('RabbitMQJob replied', [
                    'reply_to' => $replyQueue,
                    'correlation_id' => $correlationId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('RabbitMQJob publish failed', [
                'queue' => $this->queueName,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
