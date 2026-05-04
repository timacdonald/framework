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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Orchestra\Testbench\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class QueueTest extends TestCase
{
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

        unset($_SERVER['LARAVEL_CLOUD'], $_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES'], $_SERVER['SQS_PREFIX'], $_SERVER['SQS_SUFFIX']);
    }

    public function testItBindsCloudQueue()
    {
        Cloud::bootManagedQueues($this->app);

        $this->assertInstanceOf(Queue::class, $this->app['queue']->connection('sqs'));
    }

    public function testItDoesNotBindWhenManagedQueuesIsInactive()
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
        $failedJobProvider = new FailedJobProvider($eventsFake, $queue, $failerFake);
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

    public function testFindProxiesToFailerForNonCloudUrls()
    {
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        $job = $provider->find('not-a-cloud-url');

        $this->assertNull($job);
    }

    public function testFindMakesHttpRequestForCloudUrls()
    {
        Http::fake([
            'https://cloud.laravel.com/api/*' => Http::response([
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['id' => 123]),
                'id' => 'test-job-id',
            ]),
        ]);

        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        $job = $provider->find('https://cloud.laravel.com/api/jobs/test-job-id');

        $this->assertNotNull($job);
        $this->assertEquals('database', $job->connection);
        $this->assertEquals('default', $job->queue);
        $this->assertEquals(json_encode(['id' => 123]), $job->payload);
        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.laravel.com/api/jobs/test-job-id');
    }

    public function testFindReturnsNullForInvalidResponse()
    {
        Http::fake([
            'https://cloud.laravel.com/api/*' => Http::response([
                'invalid' => 'response',
            ]),
        ]);

        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        $job = $provider->find('https://cloud.laravel.com/api/jobs/test-job-id');

        $this->assertNull($job);
    }

    public function testFindCachesResultForForget()
    {
        Http::fake([
            'https://cloud.laravel.com/api/*' => Http::response([
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['id' => 123]),
                'id' => 'cached-job-id',
            ]),
        ]);

        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        // First find caches the result
        $job = $provider->find('https://cloud.laravel.com/api/jobs/cached-job-id');
        $this->assertNotNull($job);

        // Second find makes another HTTP request (no memoization in find)
        Http::assertSentCount(1);
        $job2 = $provider->find('https://cloud.laravel.com/api/jobs/cached-job-id');
        Http::assertSentCount(2);

        // But forget uses the cached data from the most recent find
        $result = $provider->forget('https://cloud.laravel.com/api/jobs/cached-job-id');
        $this->assertTrue($result);
    }

    public function testForgetProxiesToFailerForUncachedJobs()
    {
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        // First log a job to the failer with a UUID
        $uuid = (string) Str::uuid();
        $failer->log('database', 'default', json_encode(['uuid' => $uuid]), new \Exception('test'));
        $jobId = $failer->ids()[0];

        // Forget should delegate to the underlying failer
        $result = $provider->forget($jobId);

        $this->assertTrue($result);
        $this->assertEmpty($failer->ids());
    }

    public function testForgetEmitsEventForCachedCloudJobs()
    {
        Http::fake([
            'https://cloud.laravel.com/api/*' => Http::response([
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['id' => 123]),
                'id' => 'forget-test-id',
            ]),
        ]);

        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);
        $failer = $this->fakeFailer();
        $provider = new FailedJobProvider($eventsFake, $queue, $failer);

        $provider->find('https://cloud.laravel.com/api/jobs/forget-test-id');

        $result = $provider->forget('https://cloud.laravel.com/api/jobs/forget-test-id');

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
