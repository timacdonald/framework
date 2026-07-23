<?php

namespace Illuminate\Tests\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Failed\BulkForgetFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Collection;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueRetryCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testRetriesSingleJobByPushingItsRawPayload()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('5')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '5'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('5');

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    public function testRetriesSingleJobByPushingItsRawPayloadWithOptions()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(SqsQueue::class);

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('5')->andReturn($job);
        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->andReturn(['MySpecialOption' => 'option-1']);
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '5'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->shouldReceive('forget')->once()->with('5');

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenJobIsNotFound()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('find')->once()->with('123')->andReturn(null);

        $output = $this->runRetryCommand(['id' => ['123']], $failer, []);

        $this->assertStringContainsString('Unable to find failed job with ID [123].', $output);
    }

    public function testRetriesAllFailedJobsUsingTheProvidersIds()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('ids')->once()->withNoArgs()->andReturn(['1', '2']);

        $failer->shouldReceive('find')->once()->with('1')->andReturn($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('1');

        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'emails'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'emails', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $output = $this->runRetryCommand(['id' => ['all']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Pushing failed queue jobs back onto the queue.', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testRetriesJobsOnTheSpecifiedQueue()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('ids')->once()->with('emails')->andReturn(['2']);
        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'emails'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'emails', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $this->runRetryCommand(['--queue' => 'emails'], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenTheSpecifiedQueueHasNoFailedJobs()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('ids')->once()->with('emails')->andReturn([]);

        $output = $this->runRetryCommand(['--queue' => 'emails'], $failer, []);

        $this->assertStringContainsString('Unable to find failed jobs for queue [emails].', $output);
        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testRetriesJobsWithinTheGivenIdRange()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with(1)->andReturn($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(1);

        $failer->shouldReceive('find')->once()->with(2)->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(2);

        $failer->shouldReceive('find')->once()->with(3)->andReturn($this->failedJob(id: '3', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '3'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(3);

        $this->runRetryCommand(['--range' => ['1-3']], $failer, ['database' => $queue]);
    }

    public function testDisplaysInfoWhenThereAreNoJobsToRetry()
    {
        $failer = m::mock(FailedJobProviderInterface::class);

        $output = $this->runRetryCommand(['id' => []], $failer, []);

        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testItResetsAttemptsCountWhenRetryingAJob()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default', payload: ['attempts' => 5]);

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function ($payload) {
            return json_decode($payload, true)['attempts'] === 0;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testRefreshesTheRetryUntilTimestampWhenTheJobDefinesRetryUntil()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 0],
            job: new QueueRetryCommandTestJobWithRetryUntil(retryUntil: 1234567890)
        );

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function ($payload) {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testPassesQueueableOptionsToTheQueueWhenRetryingASingleJob()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(SqsQueue::class);

        $job = $this->failedJob(id: '1', connection: 'sqs', queue: 'default');

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->andReturn(['MySpecialOption' => 'option-1']);
        $queue->shouldReceive('pushRaw')->once()->with(m::type('string'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['sqs' => $queue]);
    }

    public function testDispatchesRetryRequestedEventWhenRetryingASingleJob()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);
        $events = m::mock(Dispatcher::class);

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $events->shouldReceive('dispatch')->once()->with(m::type(JobRetryRequested::class));
        $queue->shouldReceive('pushRaw')->once();
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue], $events);
    }

    public function testRetriesCollectionOfJobsUsingPushBulkRawWhenTheQueueSupportsIt()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(SqsQueue::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'sqs', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'sqs', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', m::type('string'))
            ->andReturn(['MySpecialOption' => 'option-1']);
        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', m::type('string'))
            ->andReturn(['MySpecialOption' => 'option-2']);

        $queue->shouldReceive('pushBulkRaw')->once()->with([
            ['payload' => $this->retriedPayload(id: 'job-1'), 'options' => ['MySpecialOption' => 'option-1']],
            ['payload' => $this->retriedPayload(id: 'job-2'), 'options' => ['MySpecialOption' => 'option-2']],
        ], 'default');

        $failer->shouldReceive('forget')->once()->with('job-1');
        $failer->shouldReceive('forget')->once()->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['sqs' => $queue]);
    }

    public function testRetriesCollectionOfJobsWithPushRawWhenTheQueueDoesNotSupportBulkRawPushes()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRetriesCollectionOfJobsGroupedByConnectionAndQueue()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $sqs = m::mock(SqsQueue::class);
        $database = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'sqs', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'sqs', queue: 'emails'),
            'job-3' => $this->failedJob(id: 'job-3', connection: 'database', queue: 'default'),
            'job-4' => $this->failedJob(id: 'job-4', connection: 'database', queue: 'high'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $sqs->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', m::type('string'))
            ->andReturn(['MySpecialOption' => 'option-1']);
        $sqs->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'emails', m::type('string'))
            ->andReturn(['MySpecialOption' => 'option-2']);
        $sqs->shouldReceive('pushBulkRaw')->once()->with([
            ['payload' => $this->retriedPayload(id: 'job-1'), 'options' => ['MySpecialOption' => 'option-1']],
        ], 'default');
        $sqs->shouldReceive('pushBulkRaw')->once()->with([
            ['payload' => $this->retriedPayload(id: 'job-2'), 'options' => ['MySpecialOption' => 'option-2']],
        ], 'emails');

        $database->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-3'), 'default', []);
        $database->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-4'), 'high', []);

        $failer->shouldReceive('forget')->times(4);

        $this->runRetryCommand(['id' => ['batch']], $failer, ['sqs' => $sqs, 'database' => $database]);
    }

    public function testRetriesCollectionOfJobsDispatchesRetryRequestedEventForEachJob()
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);
        $events = m::mock(Dispatcher::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);
        $events->shouldReceive('dispatch')->twice()->with(m::type(JobRetryRequested::class));
        $queue->shouldReceive('pushRaw')->twice();
        $failer->shouldReceive('forget')->twice();

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue], $events);
    }

    public function testForgetsCollectionOfJobsInBulkWhenTheProviderSupportsIt()
    {
        $failer = m::mock(FailedJobProviderInterface::class, BulkForgetFailedJobProvider::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);
        $queue->shouldReceive('pushRaw')->twice();
        $failer->shouldNotReceive('forget');
        $failer->shouldReceive('forgetMany')->once()->with(['job-1', 'job-2']);

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    private function failedJob(
        string $id,
        string $connection,
        string $queue,
        array $payload = [],
        $job = new QueueRetryCommandTestJob,
    ): stdClass {
        return (object) [
            'id' => $id,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => $id,
                'displayName' => get_class($job),
                'data' => [
                    'commandName' => get_class($job),
                    'command' => serialize($job),
                ],
                ...$payload,
            ]),
        ];
    }

    private function retriedPayload(string $id, $job = new QueueRetryCommandTestJob): string
    {
        return json_encode([
            'uuid' => $id,
            'displayName' => QueueRetryCommandTestJob::class,
            'data' => [
                'commandName' => get_class($job),
                'command' => serialize($job),
            ],
        ]);
    }

    private function runRetryCommand(array $input, FailedJobProviderInterface $failer, array $connections, $events = null): string
    {
        $container = new Application;

        $container->instance('queue.failer', $failer);

        $manager = m::mock(\stdClass::class);

        foreach ($connections as $name => $queue) {
            $manager->shouldReceive('connection')->with($name)->andReturn($queue);
        }

        $container->instance('queue', $manager);

        if (is_null($events)) {
            $events = m::mock(Dispatcher::class);
            $events->shouldReceive('dispatch');
        }

        $container->instance('events', $events);

        $command = new RetryCommand;
        $command->setLaravel($container);

        $output = new BufferedOutput;
        $command->run(new ArrayInput($input), $output);

        return $output->fetch();
    }
}

class QueueRetryCommandTestJob
{
    //
}

class QueueRetryCommandTestJobWithRetryUntil
{
    public function __construct(private int $retryUntil = 0)
    {
        //
    }

    public function retryUntil()
    {
        return $this->retryUntil;
    }
}
