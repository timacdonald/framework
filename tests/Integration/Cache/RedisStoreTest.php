<?php

namespace Illuminate\Tests\Integration\Cache;

use DateTime;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;

class RedisStoreTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRedis();

        Redis::flushAll();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->tearDownRedis();
    }

    public function testCacheTtl(): void
    {
        $store = Cache::store('redis');
        $store->clear();

        while ((microtime(true) - time()) > 0.5 && (microtime(true) - time()) < 0.6) {
            //
        }

        $store->put('hello', 'world', 1);
        $putAt = microtime(true);

        Sleep::for(600)->milliseconds();
        $this->assertTrue((microtime(true) - $putAt) < 1);
        $this->assertSame('world', $store->get('hello'));

        // Although this key expires after exactly 1 second, Redis has a
        // 0-1 millisecond error rate on expiring keys (as of Redis 2.6) so
        // for a non-flakey test we need to account for the millisecond.
        // see: https://redis.io/commands/expire/
        while ((microtime(true) - $putAt) < 1.001) {
            //
        }

        $this->assertNull($store->get('hello'));
    }

    public function testItCanStoreInfinite()
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', INF);
        $this->assertTrue($result);
        $this->assertSame(INF, Cache::store('redis')->get('foo'));

        $result = Cache::store('redis')->put('bar', -INF);
        $this->assertTrue($result);
        $this->assertSame(-INF, Cache::store('redis')->get('bar'));
    }

    public function testItCanStoreNan()
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', NAN);
        $this->assertTrue($result);
        $this->assertNan(Cache::store('redis')->get('foo'));
    }

    public function testItCanExpireWithZeroTTL()
    {
        Cache::store('redis')->clear();

        $result = Cache::store('redis')->put('foo', 10, 10);
        $this->assertTrue($result);

        $result = Cache::store('redis')->put('foo', 10, 0);
        $this->assertTrue($result);

        $value = Cache::store('redis')->get('foo');
        $this->assertNull($value);
    }

    public function testTagsCanBeAccessed()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('name', 'Sally', 5);
        Cache::store('redis')->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertEquals('Sally', Cache::store('redis')->tags(['people', 'author'])->get('name'));
        $this->assertEquals(30, Cache::store('redis')->tags(['people', 'author'])->get('age'));

        Cache::store('redis')->tags(['people', 'author'])->flush();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(0, count($keyCount));
    }

    public function testTagEntriesCanBeStoredForever()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->forever('name', 'Sally');
        Cache::store('redis')->tags(['people', 'author'])->forever('age', 30);

        $this->assertEquals('Sally', Cache::store('redis')->tags(['people', 'author'])->get('name'));
        $this->assertEquals(30, Cache::store('redis')->tags(['people', 'author'])->get('age'));

        Cache::store('redis')->tags(['people', 'author'])->flush();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(0, count($keyCount));
    }

    public function testTagEntriesCanBeIncremented()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->put('person-1', 0, 5);
        Cache::store('redis')->tags(['votes'])->increment('person-1');
        Cache::store('redis')->tags(['votes'])->increment('person-1');

        $this->assertEquals(2, Cache::store('redis')->tags(['votes'])->get('person-1'));

        Cache::store('redis')->tags(['votes'])->decrement('person-1');
        Cache::store('redis')->tags(['votes'])->decrement('person-1');

        $this->assertEquals(0, Cache::store('redis')->tags(['votes'])->get('person-1'));
    }

    public function testIncrementedTagEntriesProperlyTurnStale()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->add('person-1', 0, $seconds = 1);
        Cache::store('redis')->tags(['votes'])->increment('person-1');
        Cache::store('redis')->tags(['votes'])->increment('person-1');

        sleep(2);

        Cache::store('redis')->tags(['votes'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(0, count($keyCount));
    }

    public function testPastTtlTagEntriesAreNotAdded()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->add('person-1', 0, new DateTime('yesterday'));

        $value = Cache::store('redis')->tags(['votes'])->get('person-1');
        $this->assertNull($value);

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(0, count($keyCount));
    }

    public function testPutPastTtlTagEntriesProperlyTurnStale()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['votes'])->put('person-1', 0, new DateTime('yesterday'));
        Cache::store('redis')->tags(['votes'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(0, count($keyCount));
    }

    public function testTagsCanBeFlushedBySingleKey()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('person-1', 'Sally', 5);
        Cache::store('redis')->tags(['people', 'artist'])->put('person-2', 'John', 5);

        Cache::store('redis')->tags(['artist'])->flush();

        $this->assertEquals('Sally', Cache::store('redis')->tags(['people', 'author'])->get('person-1'));
        $this->assertNull(Cache::store('redis')->tags(['people', 'artist'])->get('person-2'));

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(3, count($keyCount)); // Sets for people, authors, and actual entry for Sally
    }

    public function testStaleEntriesCanBeFlushed()
    {
        Cache::store('redis')->clear();

        Cache::store('redis')->tags(['people', 'author'])->put('person-1', 'Sally', 1);
        Cache::store('redis')->tags(['people', 'artist'])->put('person-2', 'John', 1);

        sleep(2);

        // Add a non-stale entry to people...
        Cache::store('redis')->tags(['people', 'author'])->put('person-3', 'Jennifer', 5);

        Cache::store('redis')->tags(['people'])->flushStale();

        $keyCount = Cache::store('redis')->connection()->keys('*');
        $this->assertEquals(4, count($keyCount)); // Sets for people, authors, and artists + individual entry for Jennifer
    }

    public function testMultipleItemsCanBeSetAndRetrieved()
    {
        $store = Cache::store('redis');
        $result = $store->put('foo', 'bar', 10);
        $resultMany = $store->putMany([
            'fizz' => 'buz',
            'quz' => 'baz',
        ], 10);
        $this->assertTrue($result);
        $this->assertTrue($resultMany);
        $this->assertEquals([
            'foo' => 'bar',
            'fizz' => 'buz',
            'quz' => 'baz',
            'norf' => null,
        ], $store->many(['foo', 'fizz', 'quz', 'norf']));

        $this->assertEquals([], $store->many([]));
    }

    public function testItCanMemoizeGet()
    {
        $redis = Cache::store('redis');
        $redis->put('name', 'Tim');

        $value = Cache::memo('redis')->get('name');
        $redis->put('name', 'Taylor');

        $this->assertSame('Tim', $value);
        $this->assertSame('Tim', Cache::memo('redis')->get('name'));
        $this->assertSame('Taylor', $redis->get('name'));
    }

    public function testNullValuesAreMemoizedRatherThanReRetrievedWithGet()
    {
        $redis = Cache::store('redis');

        $value = Cache::memo('redis')->get('name');
        $redis->put('name', 'Taylor');

        $this->assertNull($value);
        $this->assertNull(Cache::memo('redis')->get('name'));
        $this->assertSame('Taylor', $redis->get('name'));
    }

    public function testItCanMemoizeGetMany()
    {
        $redis = Cache::store('redis');
        $redis->put('name.0', 'Tim');
        $redis->put('name.1', 'Taylor');

        $values = Cache::memo('redis')->getMultiple(['name.0', 'name.1']);

        $redis->put('name.0', 'MacDonald');
        $redis->put('name.1', 'Otwell');

        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $values);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], Cache::memo('redis')->getMultiple(['name.0', 'name.1']));
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $redis->getMultiple(['name.0', 'name.1']));
    }

    public function testNullValuesAreMemoizedRatherThanReRetrievedWithGetMany()
    {
        $redis = Cache::store('redis');

        $values = Cache::memo('redis')->getMultiple(['name.0', 'name.1']);

        $redis->put('name.0', 'MacDonald');
        $redis->put('name.1', 'Otwell');

        $this->assertSame(['name.0' => null, 'name.1' => null], $values);
        $this->assertSame(['name.0' => null, 'name.1' => null], Cache::memo('redis')->getMultiple(['name.0', 'name.1']));
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $redis->getMultiple(['name.0', 'name.1']));
    }

    public function testItCanRetrieveMemoizedAndNotYetMemoizedValues()
    {
        $redis = Cache::store('redis');
        $redis->put('name.0', 'Tim');
        $redis->put('name.1', 'Taylor');

        $value = Cache::memo('redis')->get('name.0');
        $redis->put('name.0', 'MacDonald');

        $values = Cache::memo('redis')->get(['name.0', 'name.1']);
        $redis->put('name.1', 'Otwell');

        $this->assertSame('Tim', $value);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $values);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], Cache::memo('redis')->getMultiple(['name.0', 'name.1']));
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $redis->getMultiple(['name.0', 'name.1']));
    }

    public function testPutMemoizesAndStoresInUnderlyingDriver()
    {
        $redis = Cache::store('redis');

        Cache::memo('redis')->put('name', 'Tim', 60);
        $this->assertSame('Tim', $redis->get('name'));

        $redis->put('name', 'Taylor');
        $this->assertSame('Tim', Cache::memo('redis')->get('name'));
    }

    public function testPutUpdatesAlreadyMemoizedValues()
    {
        $redis = Cache::store('redis');

        $redis->put('name', 'Tim');
        $this->assertSame('Tim', Cache::memo('redis')->get('name'));

        Cache::memo('redis')->put('name', 'Taylor', 60);
        $this->assertSame('Taylor', Cache::memo('redis')->get('name'));
    }

    public function testPutManyMemoizesAndStoresInUnderlyingDriver()
    {
        $redis = Cache::store('redis');

        Cache::memo('redis')->put(['name.0' => 'Tim', 'name.1' => 'Taylor'], 60);
        $this->assertSame('Tim', $redis->get('name.0'));
        $this->assertSame('Taylor', $redis->get('name.1'));

        $redis->put(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], 60);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], Cache::memo('redis')->get(['name.0', 'name.1']));
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $redis->get(['name.0', 'name.1']));
    }

    public function testItMemoizesIncrement()
    {
        $redis = Cache::store('redis');
        $redis->put('count', 1);

        $value = Cache::memo('redis')->increment('count');
        $this->assertSame(2, $value);
        $this->assertSame('2', $redis->get('count'));

        $redis->increment('count');
        $this->assertSame('2', Cache::memo('redis')->get('count'));
        $this->assertSame('3', $redis->get('count'));
    }

    public function testItMemoizesDecrement()
    {
        $redis = Cache::store('redis');
        $redis->put('count', 3);

        $value = Cache::memo('redis')->decrement('count');
        $this->assertSame(2, $value);
        $this->assertSame('2', $redis->get('count'));

        $redis->decrement('count');
        $this->assertSame('2', Cache::memo('redis')->get('count'));
        $this->assertSame('1', $redis->get('count'));
    }

    public function testItMemoizesForever()
    {
        $redis = Cache::store('redis');

        $result = Cache::memo('redis')->forever('name', 'Tim');
        $this->assertTrue($result);
        $this->assertSame('Tim', $redis->get('name'));

        $redis->forever('name', 'Taylor');
        $this->assertSame('Tim', Cache::memo('redis')->get('name'));
        $this->assertSame('Taylor', $redis->get('name'));
    }

    public function testItForgetsMemoizedValues()
    {
        $redis = Cache::store('redis');
        $redis->put('name', 'Tim');

        $value = Cache::memo('redis')->get('name');
        $this->assertSame('Tim', $value);

        Cache::memo('redis')->forget('name');
        $value = Cache::memo('redis')->get('name');
        $this->assertNull($value);
        $value = $redis->get('name');
        $this->assertNull($value);
    }

    public function testItFlushesMemoizedValues()
    {
        $redis = Cache::store('redis');

        Cache::memo('redis')->put('name.0', 'Tim');
        Cache::memo('redis')->put('name.1', 'Tim');

        Cache::memo('redis')->flush();

        $value = Cache::memo('redis')->get('name.0');
        $this->assertNull($value);
        $value = Cache::memo('redis')->get('name.1');
        $this->assertNull($value);
        $value = $redis->get('name.0');
        $this->assertNull($value);
        $value = $redis->get('name.1');
        $this->assertNull($value);
    }

    public function testMemoizedDriverGetsPrefix()
    {
        $this->assertSame('laravel_cache_', Cache::memo('redis')->getPrefix());

        Cache::driver('redis')->setPrefix('foo');

        $this->assertSame('foo', Cache::memo('redis')->getPrefix());
    }

    public function testMemoizedKeysArePrefixed()
    {
        $redis = Cache::store('redis');

        $redis->setPrefix('aaaa');
        $redis->put('name', 'Tim');
        $redis->setPrefix('zzzz');
        $redis->put('name', 'Taylor');

        $redis->setPrefix('aaaa');
        $value = Cache::memo('redis')->get('name');
        $this->assertSame('Tim', $value);

        $redis->setPrefix('zzzz');
        $value = Cache::memo('redis')->get('name');
        $this->assertSame('Taylor', $value);
    }

    public function testItDoesNotDispatchEvents()
    {
        // TODO
    }
}
