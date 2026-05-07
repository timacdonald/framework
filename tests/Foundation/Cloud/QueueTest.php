<?php

namespace Tests\Tests\Foundation;

use Illuminate\Foundation\Cloud;
use Illuminate\Foundation\Cloud\Events;
use Illuminate\Foundation\Cloud\FailedJobProvider;
use Illuminate\Foundation\Cloud\Queue;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Orchestra\Testbench\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class QueueTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', Str::random(32));
    }

    protected function setUp(): void
    {
        $_SERVER['LARAVEL_CLOUD'] = $_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES'] = '1';
        $_SERVER['SQS_PREFIX'] = 'https://sqs.us-east-2.amazonaws.com/1234567';
        $_SERVER['SQS_SUFFIX'] = '-env-8280cf2c-2081-47e8-a1f1-9cdfcba8618f';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['LARAVEL_CLOUD'], $_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES'], $_SERVER['SQS_PREFIX'], $_SERVER['SQS_SUFFIX'], $_SERVER['LARAVEL_CLOUD_REGION']);
    }

    public function testItSetSqsCredentialsToEcs()
    {
        $this->assertSame(null, Config::get('queue.connections.sqs.credentials'));

        Cloud::configureManagedQueues($this->app);

        $this->assertSame('ecs', Config::get('queue.connections.sqs.credentials'));
    }

    public function testItSetsTheSqsRegion()
    {
        $this->assertSame('us-east-1', Config::get('queue.connections.sqs.region'));

        Cloud::configureManagedQueues($this->app);
        $this->assertSame('us-east-1', Config::get('queue.connections.sqs.region'));

        $_SERVER['LARAVEL_CLOUD_REGION'] = 'eu-central-1';
        Cloud::configureManagedQueues($this->app);

        $this->assertSame('eu-central-1', Config::get('queue.connections.sqs.region'));
    }

    public function testItBindsCloudQueueConnector()
    {
        //
    }

    public function testItBindsCloudQueue()
    {
        Cloud::bootManagedQueues($this->app);

        $this->assertInstanceOf(Queue::class, $this->app['queue']->connection('sqs'));
    }

    public function testItBindsCloudEventsAsSingleton()
    {
        Cloud::bootManagedQueues($this->app);

        $this->assertFalse($this->app->resolved(Events::class));
        $this->assertSame($this->app[Events::class], $this->app[Events::class]);
    }

    public function testItBindsTheQueueFailer()
    {
        Cloud::bootManagedQueues($this->app);

        $this->assertInstanceOf(FailedJobProvider::class, $this->app['queue.failer']);
    }

    public function testItDoesNotBindCloudQueueWhenManagedQueuesIsInactive()
    {
        unset($_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES']);

        Cloud::bootManagedQueues($this->app);

        $this->assertInstanceOf(SqsQueue::class, $this->app['queue']->connection('sqs'));
    }

    public function testItDoesNotEmitEventsWhilePoppingWhenNoJobsAreProcessingAndNoJobsAreAvailableToPop()
    {
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queue->pop();

        $this->assertSame([], $eventsFake->emitted);
    }

    public function testItEmitsStartedEventWhenJobIsSuccessfullyPopped()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();

        $this->assertSame([[
            '_cloud_event' => 'queue',
            'timestamp' => '2000-01-02 03:04:05.060708',
            'type' => 'started',
            'queue' => 'default',
        ]], $eventsFake->emitted);
    }

    public function testItEmitsProcessedEventWhenNextJobIsAboutToPop()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();
        $this->travel(1)->second();
        $queue->pop();

        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'default',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:06.060708',
                'type' => 'processed',
                'queue' => 'default',
                'duration_ms' => 1000,
            ],
        ], $eventsFake->emitted);
    }

    public function testItDoesNotEmitEventsForTheSameJobAfterItHasBeenProcessed()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();
        $queue->pop();
        $queue->pop();
        $queue->pop();

        $this->assertCount(2, $eventsFake->emitted);
    }

    public function testItRemembersTheQueueForTheProcessedEvent()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop = [new FakeJob, new FakeJob];
        $queue->pop('first');
        $queue->pop('second');
        $queue->pop('third');

        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'first',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'processed',
                'queue' => 'first',
                'duration_ms' => 0,
            ], [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'second',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'processed',
                'queue' => 'second',
                'duration_ms' => 0,
            ],
        ], $eventsFake->emitted);
    }

    public function testItEmitsFailedJobEvents()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failerFake = $this->fakeFailer();
        $failedJobProvider = new FailedJobProvider($failerFake, $eventsFake);
        $failedJobProvider->setQueue($queue);
        $this->app[FailedJobProvider::class] = $failedJobProvider;

        $queueFake->jobsToPop[] = $jobFake = new FakeJob;
        $queue->pop();
        $jobFake->fail();
        Str::createUuidsUsingSequence([Uuid::fromString('00dc709e-90c4-70c2-87c8-9b7127d20e8f')]);
        $failedJobProvider->log('sqs', 'default', ['payload' => 'here'], new RuntimeException('Whoops!'));
        Str::createUuidsNormally();
        $queue->pop();

        unset($eventsFake->emitted[1]['exception']);
        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'default',
            ],
            [
                '_cloud_event' => 'failed_job',
                'id' => '00dc709e-90c4-70c2-87c8-9b7127d20e8f',
                'queue' => 'default',
                'started_at' => '2000-01-02 03:04:05.060708',
                'attempts' => 1,
                'payload' => [
                    'payload' => 'here',
                ],
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'failed',
                'queue' => 'default',
                'duration_ms' => 0,
            ],
        ], $eventsFake->emitted);
    }

    public function testItEmitsReleasedJobEvents()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop[] = $jobFake = new FakeJob;
        $queue->pop();
        $jobFake->release();
        $queue->pop();

        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'default',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'released',
                'queue' => 'default',
                'duration_ms' => 0,
            ],
        ], $eventsFake->emitted);
    }

    public function testItEmitsJobQueuedEvent()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queue->push(new FakeJob, queue: '1');
        $queue->pushOn('2', new FakeJob);
        $queue->pushRaw('', queue: '3');
        $queue->later(1, new FakeJob, queue: '4');
        $queue->laterOn('5', 1, new FakeJob);
        $queue->bulk([new FakeJob, new FakeJob], queue: '6');

        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '1',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '2',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '3',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '4',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '5',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '6',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '6',
            ],
        ], $eventsFake->emitted);
    }

    public function testTimestampsAreTheSameForBulkPush()
    {
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queue->bulk([new FakeJob, new FakeJob]);

        $this->assertCount(2, $eventsFake->emitted);
        // IMPORTANT: Do not freeze time to fix this test.
        $this->assertSame($eventsFake->emitted[0]['timestamp'], $eventsFake->emitted[1]['timestamp']);
    }

    public function testItCapturesDurationForMultipleJobs()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop = [new FakeJob, new FakeJob];
        $queue->pop();
        $this->travel(1)->second();
        $queue->pop();
        $this->travel(0.5)->second();
        $queue->pop();

        $this->assertSame(1000, $eventsFake->emitted[1]['duration_ms']);
        $this->assertSame(500, $eventsFake->emitted[3]['duration_ms']);
    }

    public function testItCapturesUtcTime()
    {
        date_default_timezone_set('Australia/Melbourne');
        $this->travelTo(Carbon::parse('2000-01-02 03:04:05.060708', 'Australia/Melbourne'));
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();
        $this->travel(1)->second();
        $queue->pop();

        $this->assertSame([
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-01 16:04:05.060708',
                'type' => 'started',
                'queue' => 'default',
            ],
            [
                '_cloud_event' => 'queue',
                'timestamp' => '2000-01-01 16:04:06.060708',
                'type' => 'processed',
                'queue' => 'default',
                'duration_ms' => 1000,
            ],
        ], $eventsFake->emitted);

    }

    public function testFindProxiesToFailerForNonEncryptedIds()
    {
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        $job = $provider->find('not-an-encrypted-payload');

        $this->assertNull($job);
    }

    public function testFindDecryptsEncryptedPayload()
    {
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        $encrypted = Crypt::encryptString(json_encode(['id' => 'test-job-id', 'queue' => 'default', 'payload' => '{"job":"App\\\\Jobs\\\\TestJob"}']));

        $result = $provider->find($encrypted);

        $this->assertIsObject($result);
        $this->assertSame('test-job-id', $result->id);
        $this->assertSame('default', $result->queue);
        $this->assertSame('{"job":"App\\\\Jobs\\\\TestJob"}', $result->payload);
    }

    public function testFindReturnsNullForInvalidEncryptedPayload()
    {
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        // Create a value that appears encrypted (valid base64 JSON with iv, value, mac) but can't actually be decrypted
        $tampered = base64_encode(json_encode(['iv' => 'fake', 'value' => 'fake', 'mac' => 'fake']));

        $job = $provider->find($tampered);

        $this->assertNull($job);
    }

    public function testForgetProxiesToFailerForNonEncryptedIds()
    {
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        // First log a job to the failer with a UUID
        $uuid = (string) Str::uuid();
        $failer->log('database', 'default', json_encode(['uuid' => $uuid]), new \Exception('test'));
        $jobId = $failer->ids()[0];

        // Forget should delegate to the underlying failer
        $result = $provider->forget($jobId);

        $this->assertTrue($result);
        $this->assertEmpty($failer->ids());
    }

    public function testForgetDecryptsAndEmitsEvent()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        $jobData = (object) ['id' => 'forget-test-id', 'queue' => 'default'];
        $encrypted = Crypt::encryptString(json_encode($jobData));

        $result = $provider->forget($encrypted);

        $this->assertTrue($result);
        $this->assertSame([
            [
                '_cloud_event' => 'failed_job',
                'id' => 'forget-test-id',
                'queue' => 'default',
                'retried_at' => '2000-01-02 03:04:05.060708',
            ],
        ], $eventsFake->emitted);
    }

    public function testForgetReturnsFalseForInvalidEncryptedPayload()
    {
        $eventsFake = $this->fakeEvents();
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($failer, $eventsFake);

        // Create a value that appears encrypted but can't be decrypted
        $tampered = base64_encode(json_encode(['iv' => 'fake', 'value' => 'fake', 'mac' => 'fake']));

        $result = $provider->forget($tampered);

        $this->assertFalse($result);
        $this->assertEmpty($eventsFake->emitted);
    }

    private function fakeEvents()
    {
        return new class('test-socket') extends Events
        {
            public array $emitted = [];

            public function emitMany(array $payloads): void
            {
                $this->emitted = [
                    ...$this->emitted,
                    ...$payloads,
                ];
            }
        };
    }

    private function fakeQueue()
    {
        return new class($this->app, [], null) extends QueueFake
        {
            public array $jobsToPop = [];

            public function pop($queue = null)
            {
                return array_shift($this->jobsToPop);
            }

            public function getQueue($queue)
            {
                $queue ??= 'default';

                return $_SERVER['SQS_PREFIX'].'/'.$queue.$_SERVER['SQS_SUFFIX'];
            }
        };
    }

    private function fakeFailer()
    {
        return new FileFailedJobProvider(tempnam(sys_get_temp_dir(), 'cloud_failed_job_test_'));
    }
}
