<?php

namespace Illuminate\Foundation\Cloud;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;

class QueueConnector implements ConnectorInterface
{
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
    public function connect(array $config): QueueContract
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
        Worker::$restartable = false;

        $this->app['events']->listen(WorkerStopping::class, $queue->onWorkerStopping(...));
    }

    /**
     * Configure the failed job provider.
     */
    protected function configureFailedJobProvider(Queue $queue): void
    {
        $this->app['queue.failer'] = $this->app[FailedJobProvider::class];

        $this->app['queue.failer']->setProcessingJobDetailsResolver(
            $queue->processingJobDetails(...)
        );
    }
}
