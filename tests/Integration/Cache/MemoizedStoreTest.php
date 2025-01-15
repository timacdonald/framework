<?php

namespace Illuminate\Tests\Integration\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;

class MemoizedStoreTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRedis();

        Config::set('cache.default', 'redis');
        Redis::flushAll();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->tearDownRedis();
    }

    public function testItCanMemoizeWhenRetrievingSingleValue()
    {
        Cache::put('name', 'Tim', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::put('name', 'Taylor', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Taylor', $live);
        $this->assertSame('Tim', $memoized);
    }

    public function testNullValuesAreMemoizedWhenRetrievingSingleValue()
    {
        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertNull($live);
        $this->assertNull($memoized);

        Cache::put('name', 'Taylor', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Taylor', $live);
        $this->assertNull($memoized);
    }

    public function testItCanMemoizeWhenRetrievingMulitpleValues()
    {
        Cache::put('name.0', 'Tim', 60);
        Cache::put('name.1', 'Taylor', 60);

        $live = Cache::getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::put('name.0', 'MacDonald', 60);
        Cache::put('name.1', 'Otwell', 60);

        $live = Cache::getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);
    }

    public function testNullValuesAreMemoizedWhenRetrievingMulitpleValues()
    {
        $live = Cache::getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->getMultiple(['name.0', 'name.1']);
        $this->assertSame($live, ['name.0' => null, 'name.1' => null]);
        $this->assertSame($memoized, ['name.0' => null, 'name.1' => null]);

        Cache::put('name.0', 'MacDonald', 60);
        Cache::put('name.1', 'Otwell', 60);

        $live = Cache::getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->getMultiple(['name.0', 'name.1']);
        $this->assertSame($live, ['name.0' => 'MacDonald', 'name.1' => 'Otwell']);
        $this->assertSame($memoized, ['name.0' => null, 'name.1' => null]);
    }

    public function testItCanRetrieveAlreadyMemoizedAndNotYetMemoizedValuesWhenRetrievingMulitpleValues()
    {
        Cache::put('name.0', 'Tim', 60);
        Cache::put('name.1', 'Taylor', 60);

        $live = Cache::get('name.0');
        $memoized = Cache::memo()->get('name.0');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::put('name.0', 'MacDonald', 60);
        Cache::put('name.1', 'Otwell', 60);

        $live = Cache::getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Otwell'], $memoized);
    }

    public function testPutForgetsMemoizedValue()
    {
        Cache::memo()->put('name', 'Tim', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::memo()->put('name', 'Taylor', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Taylor', $live);
        $this->assertSame('Taylor', $memoized);
    }

    public function testPutManyForgetsMemoizedValue()
    {
        Cache::memo()->put(['name.0' => 'Tim', 'name.1' => 'Taylor'], 60);

        $live = Cache::get(['name.0', 'name.1']);
        $memoized = Cache::memo()->get(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::memo()->put(['name.0' => 'MacDonald'], 60);

        $live = Cache::get(['name.0', 'name.1']);
        $memoized = Cache::memo()->get(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Taylor'], $memoized);
    }

    public function testIncrementForgetsMemoizedValue()
    {
        Cache::put('count', 1, 60);

        $live = Cache::get('count');
        $memoized = Cache::memo()->get('count');
        $this->assertSame('1', $live);
        $this->assertSame('1', $memoized);

        Cache::memo()->increment('count');

        $live = Cache::get('count');
        $memoized = Cache::memo()->get('count');
        $this->assertSame('2', $live);
        $this->assertSame('2', $memoized);
    }

    public function testDecrementForgetsMemoizedValue()
    {
        Cache::put('count', 1, 60);

        $live = Cache::get('count');
        $memoized = Cache::memo()->get('count');
        $this->assertSame('1', $live);
        $this->assertSame('1', $memoized);

        Cache::memo()->decrement('count');

        $live = Cache::get('count');
        $memoized = Cache::memo()->get('count');
        $this->assertSame('0', $live);
        $this->assertSame('0', $memoized);
    }

    public function testForeverForgetsMemoizedValue()
    {
        Cache::put('name', 'Tim', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::memo()->forever('name', 'Taylor');

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Taylor', $live);
        $this->assertSame('Taylor', $memoized);
    }

    public function testForgetForgetsMemoizedValue()
    {
        Cache::put('name', 'Tim', 60);

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::memo()->forget('name');

        $live = Cache::get('name');
        $memoized = Cache::memo()->get('name');
        $this->assertNull($live);
        $this->assertNull($memoized);
    }

    public function testFlushForgetsMemoizedValue()
    {
        Cache::put(['name.0' => 'Tim', 'name.1' => 'Taylor'], 60);

        $live = Cache::get(['name.0', 'name.1']);
        $memoized = Cache::memo()->get(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::memo()->flush();

        $live = Cache::get(['name.0', 'name.1']);
        $memoized = Cache::memo()->get(['name.0', 'name.1']);
        $this->assertSame(['name.0' => null, 'name.1' => null], $live);
        $this->assertSame(['name.0' => null, 'name.1' => null], $memoized);
    }

    public function testMemoizedDriverUsesUnderlyingDriversPrefix()
    {
        $this->assertSame('laravel_cache_', Cache::memo()->getPrefix());

        Cache::driver('redis')->setPrefix('foo');

        $this->assertSame('foo', Cache::memo()->getPrefix());
    }

    public function testMemoizedKeysArePrefixed()
    {
        // HERE
        $redis = Cache::store('redis');

        $redis->setPrefix('aaaa');
        $redis->put('name', 'Tim', 60);
        $redis->setPrefix('zzzz');
        $redis->put('name', 'Taylor', 60);

        $redis->setPrefix('aaaa');
        $value = Cache::memo('redis')->get('name');
        $this->assertSame('Tim', $value);

        $redis->setPrefix('zzzz');
        $value = Cache::memo('redis')->get('name');
        $this->assertSame('Taylor', $value);
    }

    public function testItDoesNotMemoizePutWhenUnderlyingDriverFails()
    {
        Config::set('cache.stores.fail', ['driver' => 'fail']);
        Cache::extend('fail', fn () => $this->repository(new class extends ArrayStore {
            public function put($key, $value, $seconds)
            {
                if ($value !== 'Tim') {
                    return parent::put(...func_get_args());
                }

                return false;
            }
        }));

        $result = Cache::driver('fail')->put('name', 'Taylor', 60);
        $this->assertTrue($result);
        $value = Cache::driver('fail')->get('name');
        $this->assertSame('Taylor', $value);
        $value = Cache::memo('fail')->get('name');
        $this->assertSame('Taylor', $value);

        $result = Cache::memo('fail')->put('name', 'Tim', 60);
        $this->assertFalse($result);
        $value = Cache::driver('fail')->get('name');
        $this->assertSame('Taylor', $value);
        $value = Cache::memo('fail')->get('name');
        $this->assertSame('Taylor', $value);

        $result = Cache::memo('fail')->put('name', 'Jess', 60);
        $this->assertTrue($result);
        $value = Cache::driver('fail')->get('name');
        $this->assertSame('Jess', $value);
        $value = Cache::memo('fail')->get('name');
        $this->assertSame('Jess', $value);
    }

    public function testItDoesNotMemoizePutManyWhenUnderlyingDriverFails()
    {
        Config::set('cache.stores.fail', ['driver' => 'fail']);
        Cache::extend('fail', fn () => $this->repository(new class extends ArrayStore {
            public function put($key, $value, $seconds)
            {
                if ($value !== 'Tim') {
                    return parent::put(...func_get_args());
                }

                return false;
            }
        }));

        $result = Cache::driver('fail')->put(['name.0' => 'Taylor', 'name.1' => 'Otwell'], 60);
        $this->assertTrue($result);
        $value = Cache::driver('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Taylor', 'name.1' => 'Otwell'], $value);
        $value = Cache::memo('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Taylor', 'name.1' => 'Otwell'], $value);

        $result = Cache::memo('fail')->put(['name.0' => 'Tim', 'name.1' => 'MacDonald'], 60);
        $this->assertFalse($result);
        $value = Cache::driver('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Taylor', 'name.1' => 'MacDonald'], $value); // !!!
        $value = Cache::memo('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Taylor', 'name.1' => 'Otwell'], $value);

        $result = Cache::memo('fail')->put(['name.0' => 'Jess', 'name.1' => 'Archer'], 60);
        $this->assertTrue($result);
        $value = Cache::driver('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Jess', 'name.1' => 'Archer'], $value);
        $value = Cache::memo('fail')->many(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Jess', 'name.1' => 'Archer'], $value);
    }

    public function testItDoesNotMemoizeIncrementWhenUnderlyingDriverFails()
    {
        Config::set('cache.stores.fail', ['driver' => 'fail']);
        Cache::extend('fail', fn () => $this->repository(new class extends ArrayStore {
            public function increment($key, $value = 1)
            {
                if ($value !== 2) {
                    return parent::increment(...func_get_args());
                }

                return false;
            }
        }));

        $result = Cache::driver('fail')->increment('count');
        $this->assertSame(1, $result);
        $value = Cache::driver('fail')->get('count');
        $this->assertSame(1, $value);
        $value = Cache::memo('fail')->get('count');
        $this->assertSame(1, $value);

        $result = Cache::memo('fail')->increment('count', 2);
        $this->assertFalse($result);
        $value = Cache::driver('fail')->get('count');
        $this->assertSame(1, $value);
        $value = Cache::memo('fail')->get('count');
        $this->assertSame(1, $value);

        $result = Cache::memo('fail')->increment('count', 3);
        $this->assertSame(4, $result);
        $value = Cache::driver('fail')->get('count');
        $this->assertSame(4, $value);
        $value = Cache::memo('fail')->get('count');
        $this->assertSame('4', $value);
    }

    public function testItDispatchesDecoratedDriverEventsOnly()
    {
        $redis = Cache::driver('redis');
        $events = [];
        Event::listen('*', function ($type, $event) use (&$events) {
            if ($event[0] instanceof CacheEvent) {
                $events[] = $event[0];
            }
        });

        Cache::memo('redis')->get('name');
        $this->assertCount(2, $events);
        $this->assertInstanceOf(RetrievingKey::class, $events[0]);
        $this->assertSame('redis', $events[0]->storeName);
        $this->assertSame('name', $events[0]->key);
        $this->assertInstanceOf(CacheMissed::class, $events[1]);
        $this->assertSame('redis', $events[1]->storeName);
        $this->assertSame('name', $events[1]->key);
        Cache::memo('redis')->get('name');
        $this->assertCount(2, $events);

        Cache::memo('redis')->many(['name']);
        $this->assertCount(2, $events);


        Cache::memo('redis')->many(['name.0', 'name.1']);
        $this->assertCount(5, $events);
        $this->assertInstanceOf(RetrievingManyKeys::class, $events[2]);
        $this->assertSame('redis', $events[2]->storeName);
        $this->assertSame(['name.0', 'name.1'], $events[2]->keys);
        $this->assertInstanceOf(CacheMissed::class, $events[3]);
        $this->assertSame('redis', $events[3]->storeName);
        $this->assertSame('name.0', $events[3]->key);
        $this->assertInstanceOf(CacheMissed::class, $events[4]);
        $this->assertSame('redis', $events[4]->storeName);
        $this->assertSame('name.1', $events[4]->key);

        Cache::memo('redis')->many(['name.0', 'name.1']);
        $this->assertCount(5, $events);

        Cache::memo('redis')->put('name', 'Tim', 1);
        $this->assertCount(7, $events);
        $this->assertInstanceOf(WritingKey::class, $events[5]);
        $this->assertSame('redis', $events[5]->storeName);
        $this->assertSame('name', $events[5]->key);
        $this->assertInstanceOf(KeyWritten::class, $events[6]);
        $this->assertSame('redis', $events[6]->storeName);
        $this->assertSame('name', $events[6]->key);

        Cache::memo('redis')->putMany(['name.0' => 'Tim', 'name.1' => 'Taylor']);
        $this->assertCount(11, $events);
        $this->assertInstanceOf(WritingKey::class, $events[7]);
        $this->assertSame('redis', $events[7]->storeName);
        $this->assertSame('name.0', $events[7]->key);
        $this->assertInstanceOf(KeyWritten::class, $events[8]);
        $this->assertSame('redis', $events[8]->storeName);
        $this->assertSame('name.0', $events[8]->key);
        $this->assertInstanceOf(WritingKey::class, $events[9]);
        $this->assertSame('redis', $events[9]->storeName);
        $this->assertSame('name.1', $events[9]->key);
        $this->assertInstanceOf(KeyWritten::class, $events[10]);
        $this->assertSame('redis', $events[10]->storeName);
        $this->assertSame('name.1', $events[10]->key);

        Cache::memo('redis')->increment('count');
        $this->assertCount(11, $events);

        Cache::memo('redis')->decrement('count');
        $this->assertCount(11, $events);

        Cache::memo('redis')->forever('name', 'Taylor');
        $this->assertCount(13, $events);
        $this->assertInstanceOf(WritingKey::class, $events[11]);
        $this->assertSame('redis', $events[11]->storeName);
        $this->assertSame('name', $events[11]->key);
        $this->assertInstanceOf(KeyWritten::class, $events[12]);
        $this->assertSame('redis', $events[12]->storeName);
        $this->assertSame('name', $events[12]->key);

        Cache::memo('redis')->forget('name');
        $this->assertCount(15, $events);
        $this->assertInstanceOf(ForgettingKey::class, $events[13]);
        $this->assertSame('redis', $events[13]->storeName);
        $this->assertSame('name', $events[13]->key);
        $this->assertInstanceOf(KeyForgotten::class, $events[14]);
        $this->assertSame('redis', $events[14]->storeName);
        $this->assertSame('name', $events[14]->key);

        Cache::memo('redis')->flush();
        $this->assertCount(15, $events);
    }
}
