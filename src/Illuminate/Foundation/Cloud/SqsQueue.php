<?php

namespace Illuminate\Foundation\Queue;

use Illuminate\Foundation\Cloud\Socket;
use Illuminate\Queue\SqsQueue as BaseSqsQueue;

class SqsQueue extends BaseSqsQueue
{
    /**
     * @var \Illuminate\Contracts\Queue\Job|null
     */
    protected $processingJob = null;

    /**
     * @var string
     */
    protected $processingQueue;

    public function __construct(
        protected Socket $socket,
    ) {
        //
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $result = parent::pushRaw($payload, $queue, $options);

        $this->afterJobPushed($queue);

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

        $job = parent::pop($queue);

        $this->afterJobPopped($queue, $job);

        return $job;
    }

    /**
     * Handle a job being pushed.
     *
     * @param  string|null  $queue
     */
    protected function afterJobPushed($queue)
    {
        $this->socket->emit([
            '_kind' => 'queue',
            'timestamp' => now()->toDateTimeString('microsecond'),
            'type' => 'queued',
            'queue' => $queue, // TODO will the queue be `null`? Do we need to run through a `getQueue` to normalize to a URL and then parse queue like we do in Pulse / Nightwatch?
        ]);
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

        $this->socket->emit([
            '_kind' => 'queue',
            'timestamp' => now()->toDateTimeString('microsecond'),
            'type' => match (true) {
                $this->processingJob->hasFailed() => 'failed',
                $this->processingJob->isReleased() => 'released',
                default => 'processed',
            },
            'queue' => $this->processingQueue,
        ]);

        $this->processingQueue = $this->processingJob = null;
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

        $this->processingQueue = $queue;
        $this->processingJob = $job;

        $this->socket->emit([
            '_kind' => 'queue',
            'timestamp' => now()->toDateTimeString('microsecond'),
            'type' => 'started',
            'queue' => $queue, // TODO will the queue be `null`? Do we need to run through a `getQueue` to normalize to a URL and then parse queue like we do in Pulse / Nightwatch?
        ]);
    }
}
