<?php

namespace App\Jobs;

// TEMPORARY: queue:retry check. Delete after the check.
class TrackingProbeJob extends Job
{
    protected array $payload;
    public $tries;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        if (!file_exists('/tmp/probe_ok')) {
            throw new \RuntimeException('probe fails until /tmp/probe_ok exists');
        }
    }
}
