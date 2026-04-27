<?php

namespace Illuminate\Foundation\Cloud;

use Illuminate\Queue\Connectors\ConnectorInterface;

class QueueConnector implements ConnectorInterface
{
    public function __construct(
        protected ConnectorInterface $connector,
        protected FailedJobProvider $failedJobProvider,
        protected Events $events,
    ) {
        //
    }

    public function connect(array $config)
    {
        $queue = new Queue($this->connector->connect($config), $this->events);

        $this->failedJobProvider->setProcessingJobDetailsResolver(
            $queue->processingJobDetails(...)
        );

        return $queue;
    }
}
