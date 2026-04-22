<?php

namespace Tests\Tests\Foundation;

use Illuminate\Foundation\Cloud\EventLogger;
use Illuminate\Foundation\Cloud\Queue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Orchestra\Testbench\TestCase;

class SqsQueueTest extends TestCase
{
    public function testItDoesNotEmitEventsWhilePoppingWhenNoJobsAreProcessingAndNoJobsAreAvailableToPop()
    {
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = new QueueFake($this->app, [], null);
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queue->pop();

        $this->assertSame([], $eventLoggerFake->emitted);
    }

    public function testItEmitsStartedEventWhenJobIsSuccessfullyPopped()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();

        $this->assertSame([[
            '_kind' => 'queue',
            'timestamp' => '2000-01-02 03:04:05.060708',
            'type' => 'started',
            'queue' => null,
        ]], $eventLoggerFake->emitted);
    }

    public function testItEmitsProcessedEventWhenNextJobIsAboutToPop()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();
        $this->travel(1)->second();
        $queue->pop();

        $this->assertSame([
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => null,
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:06.060708',
                'type' => 'processed',
                'queue' => null,
            ],
        ], $eventLoggerFake->emitted);
    }

    public function testItDoesNotEmitEventsForTheSameJobAfterItHasBeenProcessed()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop[] = new FakeJob;
        $queue->pop();
        $queue->pop();
        $queue->pop();
        $queue->pop();

        $this->assertCount(2, $eventLoggerFake->emitted);
    }

    public function testItRemembersTheQueueForTheProcessedEvent()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop = [new FakeJob, new FakeJob];
        $queue->pop('first');
        $queue->pop('second');
        $queue->pop('third');

        $this->assertSame([
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'first',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'processed',
                'queue' => 'first',
            ], [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => 'second',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'processed',
                'queue' => 'second',
            ]
        ], $eventLoggerFake->emitted);
    }

    public function testItEmitsFailedJobEvents()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop[] = $jobFake = new FakeJob;
        $queue->pop();
        $jobFake->fail();
        $queue->pop();

        $this->assertSame([
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => null,
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'failed',
                'queue' => null,
            ]
        ], $eventLoggerFake->emitted);
    }

    public function testItEmitsReleasedJobEvents()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queueFake->jobsToPop[] = $jobFake = new FakeJob;
        $queue->pop();
        $jobFake->release();
        $queue->pop();

        $this->assertSame([
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'started',
                'queue' => null,
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'released',
                'queue' => null,
            ]
        ], $eventLoggerFake->emitted);
    }

    public function testItEmitsJobQueuedEvent()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventLoggerFake = $this->fakeEventLogger();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventLoggerFake);

        $queue->push(new FakeJob, queue: '1');
        $queue->pushOn('2', new FakeJob);
        $queue->pushRaw('', queue: '3');
        $queue->later(1, new FakeJob, queue: '4');
        $queue->laterOn('5', 1, new FakeJob);
        $queue->bulk([new FakeJob, new FakeJob], queue: '6');

        $this->assertSame([
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '1',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '2',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '3',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '4',
            ],
                [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '5',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '6',
            ],
            [
                '_kind' => 'queue',
                'timestamp' => '2000-01-02 03:04:05.060708',
                'type' => 'queued',
                'queue' => '6',
            ]
        ], $eventLoggerFake->emitted);
    }

    private function fakeEventLogger()
    {
        return new class extends EventLogger {
            public array $emitted = [];

            public function emit(array $payload): void
            {
                $this->emitted[] = $payload;
            }
        };
    }

    private function fakeQueue()
    {
        return new class ($this->app, [], null) extends QueueFake {
            public array $jobsToPop = [];

            public function pop($queue = null)
            {
                return array_shift($this->jobsToPop);
            }
        };
    }
}
