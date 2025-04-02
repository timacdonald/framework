<?php

namespace Illuminate\Tests\Integration\Cache;

use DateInterval;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;

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
        Cache::macro('value', function (Cacheable $cacheable) {
            $key = $cacheable->cacheKey();
            $ttl = $cacheable->cacheTtl();

            return $cacheable->fromCacheValue(
                $this->remember($key, $ttl, fn () => $cacheable->toCacheValue())
            );
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->tearDownRedis();
    }

    public function test_it_can_retrieve_cachables()
    {
        $object = new class implements Cacheable
        {
            use IsCacheable;

            public function cacheKey(): string
            {
                return 'bdfl.name';
            }

            public function cacheTtl(): int
            {
                return 1;
            }

            public function toCacheValue(): ?string
            {
                return null;
            }
        };

        Cache::put('bdfl.name', 'Taylor');

        $result = Cache::value($object);
        $valueInCache = Cache::value($object);

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_puts_value_into_cache_when_missing()
    {
        $this->freezeTime();
        $object = new class implements Cacheable
        {
            use IsCacheable;

            public function cacheKey(): string
            {
                return 'time';
            }

            public function cacheTtl(): int
            {
                return 1;
            }

            public function toCacheValue(): mixed
            {
                return now()->getTimestamp();
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        $this->assertSame(now()->getTimestamp(), $result);
        $this->assertSame((string) now()->getTimestamp(), $valueInCache);

        $this->travelTo(now()->addSeconds(10));

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        $this->assertSame((string) now()->subSeconds(10)->getTimestamp(), $result);
        $this->assertSame((string) now()->subSeconds(10)->getTimestamp(), $valueInCache);
    }

    public function test_it_can_intercept_hydration_from_cache()
    {
        $object = new class implements Cacheable
        {
            use IsCacheable;

            public function cacheKey(): string
            {
                return 'bdfl.name';
            }

            public function cacheTtl(): int
            {
                return 1;
            }

            public function toCacheValue(): mixed
            {
                return 'Taylor';
            }

            public function fromCacheValue(mixed $value): mixed
            {
                return "{$value} Otwell";
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('bdfl.name');

        $this->assertSame('Taylor Otwell', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_can_use_constructor()
    {
        $userCacheableFactory = fn (int $id, array $attributes) => new class($id, $attributes) implements Cacheable
        {
            use IsCacheable;

            public function __construct(
                private int $id,
                private array $attributes,
            ) {
                sort($this->attributes);
            }

            public function cacheKey(): string
            {
                return "user:{$this->id}:".implode(',', $this->attributes);
            }

            public function cacheTtl(): int
            {
                return 1;
            }

            public function toCacheValue()
            {
                return array_intersect_key([
                    'name' => 'Taylor',
                    'email' => 'taylor@laravel.com',
                    'framework' => 'Laravel',
                ], array_flip($this->attributes));
            }

            public function fromCacheValue(array $value)
            {
                return (object) $value;
            }
        };
        $userOneWithoutFrameworkCacheable = $userCacheableFactory(1, ['name', 'email']);
        $userOneWithFrameworkCacheable = $userCacheableFactory(1, ['framework']);

        $userOneWithoutFramework = Cache::value($userOneWithoutFrameworkCacheable);
        $userOneWithFramework = Cache::value($userOneWithFrameworkCacheable);
        $userOneWithoutFrameworkInCache = Cache::get('user:1:email,name');
        $userOneWithFrameworkInCache = Cache::get('user:1:framework');

        $this->assertEquals($userOneWithoutFramework, (object) [
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
        ]);
        $this->assertEquals($userOneWithFramework, (object) [
            'framework' => 'Laravel',
        ]);
        $this->assertEquals($userOneWithoutFrameworkInCache, [
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
        ]);
        $this->assertEquals($userOneWithFrameworkInCache, [
            'framework' => 'Laravel',
        ]);
    }

    public function test_it_can_set_a_ttl()
    {
        $object = new class implements Cacheable
        {
            use IsCacheable;

            public function toCacheValue(): mixed
            {
                return 'Taylor';
            }

            public function cacheKey(): string
            {
                return 'bdfl.name';
            }

            public function cacheTtl(): DateTimeInterface|DateInterval|int
            {
                return 1;
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('bdfl.name');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $valueInCache);

        sleep(1);
        $valueInCache = Cache::get('bdfl.name');

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

interface Cacheable
{
    // cacheKey(...): string
    // toCacheValue(...): mixed
    // fromCacheValue(mixed $value, ...): mixed
}

// TODO this trait or just check method_exists($cacheable, 'fromCacheValue')
trait IsCacheable
{
    // TODO
    // protected ?string $cacheKey = null;

    public function fromCacheValue(mixed $value): mixed
    {
        return $value;
    }
}
