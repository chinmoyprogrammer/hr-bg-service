<?php

namespace App\Console\Commands;

use App\Support\RunTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;

// Gateway audit log: observability-agent (Vector) publishes one raw JSON per write request to audit.requests;
// this batches them into mbw_audit.api_audit_logs. INSERT IGNORE on (request_id, created_at) makes redelivery safe.
class ConsumeAuditLog extends Command
{
    protected $signature = 'audit:consume {--batch=500} {--flush=2}';

    protected $description = 'Consume gateway audit events from RabbitMQ into mbw_audit.api_audit_logs.';

    public const QUEUE = 'audit.requests';

    private array $rows = [];
    private $lastMsg = null;

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $flushEvery = max(1, (int) $this->option('flush'));

        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', '127.0.0.1'), (int) env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'), env('RABBITMQ_PASSWORD', 'guest'), env('RABBITMQ_VHOST', '/')
        );
        $channel = $connection->channel();
        $channel->queue_declare(self::QUEUE, false, true, false, false);
        $channel->basic_qos(null, $batch, null);

        $channel->basic_consume(self::QUEUE, '', false, false, false, false, function ($msg) use ($batch) {
            $row = self::toRow($msg->getBody());
            if ($row === null) {
                Log::warning('audit:consume dropped a non-JSON message', ['body' => substr($msg->getBody(), 0, 1000)]);
            } else {
                $this->rows[] = $row;
            }
            $this->lastMsg = $msg;
            if (count($this->rows) >= $batch) {
                $this->flush();
            }
        });

        $this->info('Consuming ' . self::QUEUE);
        $lastFlush = time();
        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, false, $flushEvery);
            } catch (AMQPTimeoutException $e) {
                // idle
            }
            if (time() - $lastFlush >= $flushEvery) {
                $this->flush();
                $lastFlush = time();
                RunTracker::beat('audit_consumer', ['host' => gethostname(), 'pid' => getmypid()]);
            }
        }

        return 0;
    }

    // Insert, then ack everything up to the last message. On a DB error nothing is acked: the exception
    // ends the process, RabbitMQ redelivers, start-worker.sh restarts it.
    private function flush(): void
    {
        if ($this->lastMsg === null) {
            return;
        }
        if ($this->rows) {
            DB::connection('audit')->table('api_audit_logs')->insertOrIgnore($this->rows);
        }
        $this->lastMsg->getChannel()->basic_ack($this->lastMsg->getDeliveryTag(), true);
        $this->rows = [];
        $this->lastMsg = null;
    }

    public static function toRow(string $body): ?array
    {
        $e = json_decode($body, true);
        if (!is_array($e) || empty($e['request_id'])) {
            return null;
        }
        $nn = fn ($v, $len = null) => ($v === null || $v === '') ? null : ($len ? mb_substr((string) $v, 0, $len) : $v);
        $ts = strtotime((string) ($e['time'] ?? '')) ?: time();

        return [
            'request_id' => substr((string) $e['request_id'], 0, 32),
            'created_at' => date('Y-m-d H:i:s', $ts),
            'service' => mb_substr((string) ($e['service'] ?? 'gateway'), 0, 50),
            'method' => mb_substr((string) ($e['method'] ?? ''), 0, 10),
            'url' => mb_substr((string) ($e['uri'] ?? ''), 0, 2048),
            'status' => (int) ($e['status'] ?? 0),
            'duration_ms' => (int) round(((float) ($e['duration'] ?? 0)) * 1000),
            'ip' => $nn($e['ip'] ?? null, 45),
            'forwarded_for' => $nn($e['xff'] ?? null, 255),
            'user_agent' => $nn($e['user_agent'] ?? null, 512),
            'user_id' => ctype_digit((string) ($e['user_id'] ?? '')) ? (int) $e['user_id'] : null,
            'user_name' => $nn($e['user_name'] ?? null, 191),
            'content_type' => $nn($e['content_type'] ?? null, 191),
            'request_length' => isset($e['request_length']) ? (int) $e['request_length'] : null,
            'request_body' => $nn($e['request_body'] ?? null),
            'response_body' => $nn($e['response_body'] ?? null),
        ];
    }
}
