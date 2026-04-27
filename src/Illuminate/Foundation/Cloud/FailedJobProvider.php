<?php

namespace Illuminate\Foundation\Cloud;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class FailedJobProvider implements FailedJobProviderInterface
{
    /**
     * The last job details resolver.
     *
     * @var  (callable(): (array{total_attempts: int, started_at: CarbonImmutable}))  $lastJobDetailsResolver
    */
    protected $lastJobDetailsResolver;

    /**
     * Create a new instance.
     *
     */
    public function __construct(
        protected Events $events,
    ) {
        //
    }

    /**
     * Log a failed job into storage.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     * @return string|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $now = CarbonImmutable::now('UTC');
        $lastJobDetails = call_user_func($this->lastJobDetailsResolver);

        $this->events->emit([
            '_cloud_event' => 'failed_job',
            'id' => $id = Str::uuid7($now)->toString(),
            'queue' => $queue,
            'started_at' => $lastJobDetails['started_at']->toDateTimeString('microsecond'),
            'total_attempts' => $lastJobDetails['total_attempts'],
            'payload' => $payload,
            'exception' => (string) mb_convert_encoding($exception, 'UTF-8'),
        ]);

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $now->toDateTimeString('microsecond'),
            'type' => 'failed',
            'queue' => $queue,
            'duration_ms' => (int) $lastJobDetails['started_at']->diffInMilliseconds($now),
        ]);

        return $id;
    }

    /**
     * Get the IDs of all of the failed jobs.
     *
     * @param  string|null  $queue
     * @return array
     */
    public function ids($queue = null)
    {
        return [];
    }

    /**
     * Get a list of all of the failed jobs.
     *
     * @return array
     */
    public function all()
    {
        return [];
    }

    /**
     * Get a single failed job.
     *
     * @param  mixed  $id
     * @return object|null
     */
    public function find($id)
    {
        return null;
    }

    /**
     * Delete a single failed job from storage.
     *
     * @param  mixed  $id
     * @return bool
     */
    public function forget($id)
    {
        return false;
    }

    /**
     * Flush all of the failed jobs from storage.
     *
     * @param  int|null  $hours
     * @return void
     */
    public function flush($hours = null)
    {
        //
    }

    /**
     * Set the last job details resolver.
     *
     * @param  (callable(): (array{total_attempts: int, started_at: CarbonImmutable}))  $lastJobDetailsResolver  $callback
     * @return $this
    */
    public function setLastJobDetailsResolver(callable $callback)
    {
        $this->lastJobDetailsResolver = $callback;

        return $this;
    }
}
