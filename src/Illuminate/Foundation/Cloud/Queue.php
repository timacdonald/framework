<?php

namespace Illuminate\Foundation\Cloud;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Traits\ForwardsCalls;

class Queue implements QueueContract, ClearableQueue
{
    use ForwardsCalls;

    /**
     * The currently processing job.
     *
     * @var \Illuminate\Contracts\Queue\Job|null
     */
    protected $processingJob = null;

    /**
     * The queue for the currently processing job.
     *
     * @var string|null
     */
    protected $processingQueue;

    /**
     * The date the last job was pushed.
     *
     * @var \Carbon\CarbonImmutable|null
     */
    protected $lastJobPushedAt = null;

    /**
     * The date the last job started processing.
     *
     * @var \Carbon\CarbonImmutable
     */
    protected $lastJobStartedAt = null;

    /**
     * The cache of normalized queue names.
     *
     * @var list<string>
     */
    protected $normalizedQueueCache = [];

    /**
     * Create a new Queue instance.
     */
    public function __construct(
        protected QueueContract $queue,
        protected Events $events,
    ) {
        //
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        return $this->queue->size(...func_get_args());
    }

    /**
     * Get the number of pending jobs.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function pendingSize($queue = null)
    {
        return $this->queue->pendingSize(...func_get_args());
    }

    /**
     * Get the number of delayed jobs.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function delayedSize($queue = null)
    {
        return $this->queue->delayedSize(...func_get_args());
    }

    /**
     * Get the number of reserved jobs.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function reservedSize($queue = null)
    {
        return $this->queue->reservedSize(...func_get_args());
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     *
     * @param  string|null  $queue
     * @return int|null
     */
    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return $this->queue->creationTimeOfOldestPendingJob(...func_get_args());
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        $this->beforeJobPushed();

        $result = $this->queue->push(...func_get_args());

        $this->afterJobPushed($queue);

        return $result;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string  $queue
     * @param  string|object  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function pushOn($queue, $job, $data = '')
    {
        $this->beforeJobPushed();

        $result = $this->queue->pushOn(...func_get_args());

        $this->afterJobPushed($queue);

        return $result;
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $this->beforeJobPushed();

        $result = $this->queue->pushRaw(...func_get_args());

        $this->afterJobPushed($queue);

        return $result;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $this->beforeJobPushed();

        $result = $this->queue->later(...func_get_args());

        $this->afterJobPushed($queue);

        return $result;
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     *
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function laterOn($queue, $delay, $job, $data = '')
    {
        $this->beforeJobPushed();

        $result = $this->queue->laterOn(...func_get_args());

        $this->afterJobPushed($queue);

        return $result;
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * @param  array  $jobs
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function bulk($jobs, $data = '', $queue = null)
    {
        $this->beforeJobPushed();

        $result = $this->queue->bulk(...func_get_args());

        $this->afterJobsPushed(count($jobs), $queue);

        return $result;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $this->beforeJobPopped();

        $job = $this->queue->pop(...func_get_args());

        $this->afterJobPopped($queue, $job);

        return $job;
    }

    /**
     * Delete all of the jobs from the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function clear($queue)
    {
        return $this->queue->clear(...func_get_args());
    }

    /**
     * Get the connection name for the queue.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return $this->queue->getConnectionName();
    }

    /**
     * Set the connection name for the queue.
     *
     * @param  string  $name
     * @return $this
     */
    public function setConnectionName($name)
    {
        $this->queue->setConnectionName(...func_get_args());

        return $this;
    }

    /**
     * Set the queue configuration array.
     *
     * @param  array  $config
     * @return $this
     */
    public function setConfig($config)
    {
        $this->queue->setConfig(...func_get_args());

        return $this;
    }

    /**
     * Get the queueable options from the job.
     *
     * @param  mixed  $job
     * @param  string|null  $queue
     * @param  string  $payload
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @return array{DelaySeconds?: int, MessageGroupId?: string, MessageDeduplicationId?: string}
     */
    public function getQueueableOptions($job, $queue, $payload, $delay = null): array
    {
        return $this->queue->getQueueableOptions(...func_get_args());
    }

    /**
     * Handle before a job is pushed.
     *
     * @return void
     */
    protected function beforeJobPushed()
    {
        $this->lastJobPushedAt = CarbonImmutable::now('UTC');
    }

    /**
     * Handle before a job is pushed.
     *
     * @param  string|null  $queue
     * @return void
     */
    protected function afterJobPushed($queue)
    {
        $this->afterJobsPushed(1, $queue);
    }

    /**
     * Handle jobs being pushed.
     *
     * @param  int  $count
     * @param  string|null  $queue
     */
    protected function afterJobsPushed($count, $queue)
    {
        $this->events->emitMany(array_fill(0, $count, [
            '_cloud_event' => 'queue',
            'timestamp' => $this->lastJobPushedAt->toDateTimeString('microsecond'),
            'type' => 'queued',
            'queue' => $this->normalizeQueue($queue),
        ]));

        $this->lastJobPushedAt = null;
    }

    /**
     * Handle a job about to be popped.
     *
     * @return void
     */
    protected function beforeJobPopped()
    {
        if (! $this->processingJob) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $now->toDateTimeString('microsecond'),
            'type' => match (true) {
                $this->processingJob->hasFailed() => 'failed',
                $this->processingJob->isReleased() => 'released',
                default => 'processed',
            },
            'queue' => $this->processingQueue,
            'duration_ms' => (int) $this->lastJobStartedAt->diffInMilliseconds($now),
        ]);

        $this->processingQueue = $this->processingJob = $this->lastJobStartedAt = null;
    }

    /**
     * Handle a job being popped.
     *
     * @param  string|null  $queue
     * @param  \Illuminate\Contracts\Queue\Job|null  $job
     * @return void
     */
    protected function afterJobPopped($queue, $job)
    {
        if (! $job) {
            return;
        }

        $this->processingJob = $job;
        $this->processingQueue = $this->normalizeQueue($queue);
        $this->lastJobStartedAt = CarbonImmutable::now('UTC');

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $this->lastJobStartedAt->toDateTimeString('microsecond'),
            'type' => 'started',
            'queue' => $this->processingQueue,
        ]);
    }

    /**
     * Normalize the queue name.
     *
     * @param  string|null  $queue
     * @return string
     */
    protected function normalizeQueue($queue)
    {
        if (array_key_exists($queue ?? '', $this->normalizedQueueCache)) {
            return $this->normalizedQueueCache[$queue];
        }

        $normalizedQueue = $this->queue->getQueue($queue);

        $prefix = preg_quote($_SERVER['SQS_PREFIX'], '#');
        $normalizedQueue = preg_replace("#^{$prefix}/#", '', $normalizedQueue);

        $suffix = preg_quote($_SERVER['SQS_SUFFIX'], '#');
        $normalizedQueue = preg_replace("#{$suffix}$#", '', $normalizedQueue);

        return $this->normalizedQueueCache[$queue] = $normalizedQueue;
    }

    /**
     * Dynamically pass method calls to the underlying dispatcher.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardDecoratedCallTo($this->queue, $method, $parameters);
    }
}
