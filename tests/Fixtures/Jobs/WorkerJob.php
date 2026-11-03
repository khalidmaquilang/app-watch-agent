<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * A queue job as a worker of a real (non-sync) connection receives it, for the given job class.
 */
class WorkerJob extends Job implements JobContract
{
    /**
     * The raw job payload.
     */
    protected string $raw_body;

    /**
     * Build the job for the given class, connection, queue and attempt.
     */
    public function __construct(string $job_class, string $connection_name, string $queue, protected int $attempt = 1)
    {
        $this->container = Container::getInstance();
        $this->connectionName = $connection_name;
        $this->queue = $queue;
        $this->raw_body = (string) json_encode([
            'uuid' => 'job-uuid',
            'displayName' => $job_class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $job_class, 'command' => ''],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * {@inheritDoc}
     */
    public function getJobId(): string
    {
        return 'job-id';
    }

    /**
     * {@inheritDoc}
     */
    public function getRawBody(): string
    {
        return $this->raw_body;
    }

    /**
     * {@inheritDoc}
     */
    public function attempts(): int
    {
        return $this->attempt;
    }
}
