<?php

namespace Illuminate\Tests\Integration\Cache;

use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Stringable;

/**
 * Does using `app()->call($c->hydrate(...), ['value' => $value])` feel clunky
 * because you _must_ call the cached value `$value`?
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

        // TODO make sure actual `value` method can infer the return
        // type of cacheables.
        Cache::macro('value', function ($cacheable) {
            $key = method_exists($cacheable, 'key')
                ? app()->call($cacheable->key(...))
                : $cacheable->key ?? null;

            if ($key === null) {
                throw new RuntimeException('Cache object must have a key defined');
            }

            $ttl = method_exists($cacheable, 'ttl')
                ? app()->call($cacheable->ttl(...))
                : $cacheable->ttl ?? null;

            $callback = app()->wrap($cacheable->resolve(...));

            $value = $this->remember($key, $ttl, $callback);

            if (method_exists($cacheable, 'hydrate')) {
                $value = app()->call($cacheable->hydrate(...), ['value' => $value]);
            }

            return $value;
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
        $valueInCache = Cache::value($object);

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
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
        $valueInCache = Cache::value($object);

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
        $valueInCache = Cache::value($object);

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
        $valueInCache = Cache::value($object);

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
            public $ttl = 1;
            public $key = 'name';

            public function resolve(): mixed
            {
                return 'Taylor';
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);

        sleep(1);

        $valueInCache = Cache::get('name');

        $this->assertNull($valueInCache);
    }

    public function test_ttl_function_takes_precedence_over_property()
    {
        $object = new class
        {
            public $key = 'name';
            public $ttl = 2;

            public function resolve(): mixed
            {
                return 'Taylor';
            }

            public function ttl()
            {
                return 1;
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);

        sleep(1);

        $valueInCache = Cache::get('name');

        $this->assertNull($valueInCache);
    }

    public function test_it_uses_method_injection_for_ttl()
    {
        $this->app->instance(MyTestService::class, new MyTestService(1));
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

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);

        sleep(1);

        $valueInCache = Cache::get('name');

        $this->assertNull($valueInCache);
    }


    public function test_it_can_invalidate_cache()
    {
        $this->markTestIncomplete();
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

    public function test_it_can_use_method_injection()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_be_used_with_locks_and_other_cache_features()
    {
        $this->markTestIncomplete('Dunno about this');
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
