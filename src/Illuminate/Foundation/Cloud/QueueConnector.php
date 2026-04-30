<?php

namespace Illuminate\Foundation\Cloud;

use Illuminate\Foundation\Application;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\App;

class QueueConnector implements ConnectorInterface
{
    /**
     * Reserved memory so that errors can emit events correctly on memory exhaustion.
     */
    private static string|null $reservedMemory = null;

    /**
     * Create a new instance.
     */
    public function __construct(
        protected ConnectorInterface $connector,
        protected Application $app,
    ) {
        //
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        $queue = new Queue($this->connector->connect($config), $this->app[Events::class]);

        $this->configureWorker($queue);
        $this->configureFailedJobProvider($queue);


        return $queue;
    }

    /**
     * Configure the queue worker.
     */
    protected function configureWorker(Queue $queue): void
    {
        if (! $this->app->runningConsoleCommand('queue:work')) {
            return;
        }

        Worker::$restartable = false;

        $this->app['events']->listen(fn (WorkerStopping $event) => match ($event->reason) {
            WorkerStopReason::TimedOut => $queue->finishProcessingJob(as: 'released'),
            default => $queue->finishProcessingJob(),
        });

        static::$reservedMemory = str_repeat('x', 32768);
        register_shutdown_function(function () use ($queue) {
            static::$reservedMemory = null;

            if (! is_null($error = error_get_last()) && in_array($error['type'], [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE])) {
                $queue->finishProcessingJob(as: 'released');
            }
        });
    }

    /**
     * Configure the failed job provider.
     */
    protected function configureFailedJobProvider(Queue $queue): void
    {
        if (! $this->app->runningConsoleCommand('queue:work')) {
            return;
        }

        $this->app['queue.failer'] = new FailedJobProvider($this->app[Events::class], $queue);
    }
}
