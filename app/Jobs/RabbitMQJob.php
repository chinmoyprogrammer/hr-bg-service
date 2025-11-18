<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    public function handle()
    {
        // Access the data passed to the job
        $message = json_encode($this->data);

        // Publish the message to RabbitMQ
        $queueConnection = app('queue')->connection('rabbitmq');

        if ($this->queueName) {
            $queueConnection->to($this->queueName)->pushRaw($message);
        } else {
            $queueConnection->pushRaw($message);
        }
    }
}