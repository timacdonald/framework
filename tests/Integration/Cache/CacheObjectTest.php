<?php

namespace Illuminate\Tests\Integration\Cache;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Stringable;

/**
 * Does using `app()->call($c->hydrate(...), ['value' => $value])` feel clunky
 * because you _must_ call the cached value `$value`?
 * How can I keep my most active users cached?
 * What does it look like in the schedule?
 * What if I want to retrieve mutliple values at once? Cache::values([...])?
 * PutMany / GetMany / WarmMany
 */
class CacheObjectTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRedis();

        Config::set('cache.default', 'redis');
        Redis::flushAll();

        $resolveKey = fn ($cacheable) => method_exists($cacheable, 'key')
            ? app()->call($cacheable->key(...))
            : $cacheable->key ?? null;

        $resolveTtl = fn ($cacheable) => method_exists($cacheable, 'ttl')
            ? app()->call($cacheable->ttl(...))
            : $cacheable->ttl ?? null;

        $hydrate = fn ($cacheable, $value) => method_exists($cacheable, 'hydrate')
            ? app()->call($cacheable->hydrate(...), ['value' => $value])
            : $value;

        Cache::macro('value', fn ($cacheable) => Cache::values([$cacheable])[0]);

        Cache::macro('values', function ($cacheables) use ($resolveKey, $resolveTtl, $hydrate) {
            $order = [];

            $mapped = collect($cacheables)
                ->mapWithKeys(function ($cacheable) use ($resolveKey, &$order) {
                    $key = (string) $resolveKey($cacheable);

                    if ($key === '') {
                        throw new RuntimeException('Cache object must have a key defined');
                    }

                    $order[] = $key;

                    return [$key => $cacheable];
                });

            $existing = Cache::many($mapped->keys()->all());

            $found = collect($existing)
                ->reject(fn ($value) => $value === null)
                ->keys();

            $missing = collect($existing)
                ->forget($found)
                ->keys();

            $resolved = $mapped
                ->except($found)
                ->groupBy(fn ($cacheable, $key) => $resolveTtl($cacheable), preserveKeys: true)
                ->flatMap(function ($ttlGroup, $ttl) {
                    $ttl = $ttl === '' ? null : $ttl;

                    $values = $ttlGroup
                        ->map(fn ($cacheable) => app()->call($cacheable->resolve(...)))
                        ->all();

                    if (count($values) === 1) {
                        Cache::put(array_keys($values)[0], array_values($values)[0], $ttl);
                    } else {
                        Cache::putMany($values, $ttl);
                    }

                    return $values;
                });

            $all = collect([...$existing, ...$resolved])
                ->map(fn ($value, $key) => $hydrate($mapped[$key], $value));

            $results = [];

            foreach ($order as $key) {
                $results[] = $all[$key];
            }

            return $results;
        });

        Cache::macro('warm', function ($cacheable, $value = null) {
            $value = func_num_args() === 1
                ? app()->call($cacheable->resolve(...))
                : $value;

            $key = method_exists($cacheable, 'key')
                ? app()->call($cacheable->key(...))
                : $cacheable->key ?? null;

            if ($key === null) {
                throw new RuntimeException('Cache object must have a key defined');
            }


//                 ? app()->call($cacheable->ttl(...))
//                 : $cacheable->ttl ?? null;

//             if (method_exists($cacheable, 'dehydrate')) {
//                 $value = app()->call($cacheable->dehydrate(...), ['value' => $value]);
//             }

//             return $this->put($key, $value, $ttl);
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->tearDownRedis();
    }

    public function test_it_can_retrieve_cache_objects()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                //
            }
        };
        Cache::put('name', 'Taylor');

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_can_retrieve_multiple_cache_objects()
    {
        $factory = fn ($key) => new class($key)
        {
            public function __construct(
                public $key,
            ) {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name.0', 'Taylor');
        Cache::put('name.1', 'Tim');

        $result = Cache::values([
            $factory('name.0'),
            $factory('name.1'),
        ]);
        $valuesInCache = Cache::many([
            'name.0',
            'name.1',
        ]);

        $this->assertSame([
            'Taylor',
            'Tim',
        ], $result);
        $this->assertSame([
            'name.0' => 'Taylor',
            'name.1' => 'Tim',
        ], $valuesInCache);
    }

    public function test_it_can_retrieve_multiple_cache_object_with_duplicate_keys()
    {
        $factory = fn ($key) => new class($key)
        {
            public function __construct(
                public $key,
            ) {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name.0', 'Taylor');
        Cache::put('name.1', 'Tim');

        $result = Cache::values([
            $factory('name.0'),
            $factory('name.1'),
            $factory('name.1'),
        ]);
        $valuesInCache = Cache::many([
            'name.0',
            'name.1',
            'name.1',
        ]);

        $this->assertSame([
            'Taylor',
            'Tim',
            'Tim',
        ], $result);

        $this->assertSame([
            'name.0' => 'Taylor',
            'name.1' => 'Tim',
        ], $valuesInCache);
    }

    public function test_it_maintains_order_when_retrieving_multiple_cache_object_with_duplicate_keys()
    {
        $factory = fn ($key) => new class($key)
        {
            public function __construct(
                public $key,
            ) {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name.0', 'Taylor');
        Cache::put('name.1', 'Tim');

        $result = Cache::values([
            $factory('name.1'),
            $factory('name.0'),
            $factory('name.1'),
        ]);
        $valuesInCache = Cache::many([
            'name.1',
            'name.0',
            'name.1',
        ]);

        $this->assertSame([
            'Tim',
            'Taylor',
            'Tim',
        ], $result);

        $this->assertSame([
            'name.1' => 'Tim',
            'name.0' => 'Taylor',
        ], $valuesInCache);
    }

    public function test_it_retrieves_all_existing_cache_items_in_one_cache_call()
    {
        $factory = fn ($key) => new class($key)
        {
            public function __construct(
                public $key,
            ) {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name.0', 'Taylor');
        Cache::put('name.1', 'Tim');
        Event::fake([CacheHit::class, RetrievingManyKeys::class]);

        $result = Cache::values([
            $factory('name.0'),
            $factory('name.1'),
        ]);

        Event::assertDispatched(RetrievingManyKeys::class, 1);
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === ['name.0', 'name.1']);
    }

    public function test_it_puts_all_missing_cache_items_into_cache_via_one_cache_call_per_ttl()
    {
        $factory = fn ($key, $value, $ttl) => new class($key, $value, $ttl)
        {
            public function __construct(
                public $key,
                public $value,
                public $ttl,
            ) {
                //
            }

            public function resolve()
            {
                return $this->value;
            }
        };
        Event::fake([RetrievingManyKeys::class, WritingManyKeys::class]);

        $result = Cache::values([
            $factory('name.0', 'Taylor', 5),
            $factory('name.1', 'Tim', 5),
            $factory('name.2', 'Jess', 10),
            $factory('name.3', 'Ryuta', 10),
            $factory('name.4', 'Sabrina', 5),
        ]);

        Event::assertDispatched(RetrievingManyKeys::class, 1);
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === [
            'name.0', 'name.1', 'name.2', 'name.3', 'name.4',
        ]);
        Event::assertDispatched(WritingManyKeys::class, 2);
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'name.0', 'name.1', 'name.4',
        ] && $event->seconds === 5);
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'name.2', 'name.3',
        ] && $event->seconds === 10);
    }

    public function test_it_puts_missing_cache_item_into_cache_via_one_put_call_when_no_shared_ttl_exists()
    {
        $factory = fn ($key, $value, $ttl) => new class($key, $value, $ttl)
        {
            public function __construct(
                public $key,
                public $value,
                public $ttl,
            ) {
                //
            }

            public function resolve()
            {
                return $this->value;
            }
        };
        Event::fake([RetrievingManyKeys::class, WritingManyKeys::class, WritingKey::class]);

        $result = Cache::values([
            $factory('name.0', 'Taylor', 5),
            $factory('name.1', 'Tim', 10),
        ]);

        Event::assertDispatched(RetrievingManyKeys::class, 1);
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === [
            'name.0', 'name.1',
        ]);
        Event::assertDispatched(WritingManyKeys::class, 0);
        Event::assertDispatched(fn (WritingKey $event) => $event->key === 'name.0' && $event->seconds === 5);
        Event::assertDispatched(fn (WritingKey $event) => $event->key === 'name.1' && $event->seconds === 10);
    }

    public function test_it_use_method_injection_for_resolve()
    {
        $this->app->instance(MyTestService::class, new MyTestService('Taylor'));
        $object = new class
        {
            public $key = 'name';

            public function resolve(MyTestService $service)
            {
                return $service->value;
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_its_key_function_takes_precedence_over_property()
    {
        $object = new class
        {
            public $key = 'foo';

            public function key()
            {
                return 'name';
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name', 'Taylor');

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_uses_method_injection_for_key()
    {
        $this->app->instance(MyTestService::class, new MyTestService('name'));
        $object = new class
        {
            public function key(MyTestService $service)
            {
                return $service->value;
            }

            public function resolve()
            {
                //
            }
        };
        Cache::put('name', 'Taylor');

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_requires_a_key()
    {
        $object = new class
        {
            public function resolve()
            {
                //
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cache object must have a key defined');

        Cache::value($object);
    }

    public function test_it_puts_value_into_cache_when_missing()
    {
        $this->freezeTime();
        $object = new class
        {
            public $key = 'time';

            public function resolve()
            {
                return now()->getTimestamp();
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        // Note this comes out as an int, while the others come out as a
        // string. This is expected from an implementation point of view.
        // Might be weird from a consumer perspective.
        $this->assertSame(now()->getTimestamp(), $result);
        $this->assertSame((string) now()->getTimestamp(), $valueInCache);

        $this->travelTo(now()->addSeconds(10));

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        $this->assertSame((string) now()->subSeconds(10)->getTimestamp(), $result);
        $this->assertSame((string) now()->subSeconds(10)->getTimestamp(), $valueInCache);
    }

    public function test_it_can_hydrate_when_value_is_put_into_cache()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function hydrate($value)
            {
                return "{$value} Otwell";
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor Otwell', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_can_hydrate_when_value_is_already_in_the_cache()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function hydrate($value)
            {
                return "{$value} Otwell";
            }
        };

        Cache::put('name', 'Abigail');

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Abigail Otwell', $result);
        $this->assertSame('Abigail', $valueInCache);
    }

    public function test_it_uses_method_injection_for_hydrate()
    {
        $this->app->instance(MyTestService::class, new MyTestService('Otwell'));
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function hydrate($value, MyTestService $service)
            {
                return "{$value} {$service->value}";
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor Otwell', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_owns_the_objects_constructor()
    {
        $userCacheableFactory = fn (int $id, array $attributes) => new class($id, $attributes)
        {
            public function __construct(
                private int $id,
                private array $attributes,
            ) {
                sort($this->attributes);
            }

            public function key()
            {
                return "user:{$this->id}:".implode(',', $this->attributes);
            }

            public function resolve()
            {
                return array_intersect_key([
                    'name' => 'Taylor',
                    'email' => 'taylor@laravel.com',
                    'framework' => 'Laravel',
                ], array_flip($this->attributes));
            }
        };
        $userOneWithoutFrameworkCacheable = $userCacheableFactory(1, ['name', 'email']);
        $userOneWithFrameworkCacheable = $userCacheableFactory(1, ['framework']);

        $userOneWithoutFrameworkResult = Cache::value($userOneWithoutFrameworkCacheable);
        $userOneWithFrameworkResult = Cache::value($userOneWithFrameworkCacheable);
        $userOneWithoutFrameworkValueInCache = Cache::get('user:1:email,name');
        $userOneWithFrameworkValueInCache = Cache::get('user:1:framework');

        $this->assertSame($userOneWithoutFrameworkResult, [
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
        ]);
        $this->assertSame($userOneWithFrameworkResult, [
            'framework' => 'Laravel',
        ]);
        $this->assertSame($userOneWithoutFrameworkValueInCache, [
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
        ]);
        $this->assertSame($userOneWithFrameworkValueInCache, [
            'framework' => 'Laravel',
        ]);
    }

    public function test_it_can_set_a_ttl()
    {
        $object = new class
        {
            public $ttl = 99;
            public $key = 'name';

            public function resolve(): mixed
            {
                return 'Taylor';
            }
        };

        Cache::value($object);
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertEqualsWithDelta(99, $ttl, 1);
    }

    public function test_ttl_function_takes_precedence_over_property()
    {
        $object = new class
        {
            public $key = 'name';
            public $ttl = 1;

            public function resolve(): mixed
            {
                return 'Taylor';
            }

            public function ttl()
            {
                return 99;
            }
        };

        Cache::value($object);
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertEqualsWithDelta(99, $ttl, 1);
    }

    public function test_it_uses_method_injection_for_ttl()
    {
        $this->app->instance(MyTestService::class, new MyTestService(99));
        $object = new class
        {
            public $key = 'name';

            public function resolve(): mixed
            {
                return 'Taylor';
            }

            public function ttl(MyTestService $service)
            {
                return $service->value;
            }
        };

        Cache::value($object);
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertEqualsWithDelta(99, $ttl, 1);
    }

    public function test_it_can_warm_the_cache_with_in_memory_value()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                // return 'Taylor';
            }

            public function dehydrate($value)
            {
                return preg_replace('/ Otwell$/', '', $value);
            }

            public function hydrate($value)
            {
                return "{$value} Otwell";
            }
        };

        $returnedWarmValue = Cache::warm($object, 'Taylor Otwell');

        $valueInCache = Cache::get('name');
        $result = Cache::value($object);

        $this->assertSame('Taylor', $valueInCache);
        $this->assertSame('Taylor Otwell', $result);
        $this->assertTrue($returnedWarmValue);
    }

    public function test_it_can_warm_the_cache_without_in_memory_value()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function dehydrate($value)
            {
                return preg_replace('/ Otwell$/', '', $value);
            }

            public function hydrate($value)
            {
                return "{$value} Otwell";
            }
        };

        $returnedWarmValue = Cache::warm($object);

        $valueInCache = Cache::get('name');
        $result = Cache::value($object);

        $this->assertSame('Taylor', $valueInCache);
        $this->assertSame('Taylor Otwell', $result);
        $this->assertTrue($returnedWarmValue);
    }

    public function test_it_can_be_nicely_tied_into_eloquent_events_to_stay_up_to_date()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_use_memo()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_also_memoize_hydrated_values()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_use_flexible()
    {
        $this->markTestIncomplete();
    }

    public function test_it_has_access_to_the_cache_driver_on_the_object()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_be_used_with_locks_and_other_cache_features()
    {
        $this->markTestIncomplete('Dunno about this');
    }

    public function test_it_has_access_to_the_cache()
    {
        $this->markTestIncomplete();
    }
}

class MyTestService
{
    public function __construct(
        public $value,
    ) {
        //
    }
}
