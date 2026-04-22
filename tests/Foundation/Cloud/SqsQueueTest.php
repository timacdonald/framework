<?php

namespace Tests\Tests\Foundation;

use Illuminate\Foundation\Cloud\Events;
use Illuminate\Foundation\Cloud\Queue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Orchestra\Testbench\TestCase;

class SqsQueueTest extends TestCase
{
    public function testItDoesNotEmitEventsWhilePoppingWhenNoJobsAreProcessingAndNoJobsAreAvailableToPop()
    {
        $eventsFake = $this->fakeEvents();
        $queueFake = new QueueFake($this->app, [], null);
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
            '_kind' => 'queue',
            'timestamp' => '2000-01-02 03:04:05.060708',
            'type' => 'started',
            'queue' => null,
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
        ], $eventsFake->emitted);
    }

    public function testItEmitsFailedJobEvents()
    {
        $this->travelTo('2000-01-02 03:04:05.060708');
        $eventsFake = $this->fakeEvents();
        $queueFake = $this->fakeQueue();
        $queue = new Queue($queueFake, $eventsFake);

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

    private function fakeEvents()
    {
        return new class extends Events {
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
        return new class ($this->app, [], null) extends QueueFake {
            public array $jobsToPop = [];

            public function pop($queue = null)
            {
                return array_shift($this->jobsToPop);
            }
        };
    }
}
