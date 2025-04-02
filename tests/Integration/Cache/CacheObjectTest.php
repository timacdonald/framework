<?php

namespace Illuminate\Tests\Integration\Cache;

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

        Cache::macro('value', function (Cacheable $cacheable) {
            $key = $cacheable->cacheKey();

            $value = Cache::get($key);

            if ($value === null) {
                $value = $cacheable->toCacheValue();

                Cache::put($key, $value);

                return $cacheable->fromCacheValue($value);
            }

            return $cacheable->fromCacheValue($value);
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

            public function toCacheValue(): mixed
            {
                return now()->getTimestamp();
            }

            public function cacheKey(): string
            {
                return 'time';
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

            public function fromCacheValue(mixed $value): mixed
            {
                return "{$value} Otwell";
            }

            public function toCacheValue(): mixed
            {
                return 'Taylor';
            }

            public function cacheKey(): string
            {
                return 'bdfl.name';
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

            public function fromCacheValue(array $value)
            {
                return (object) $value;
            }

            public function toCacheValue()
            {
                return array_intersect_key([
                    'name' => 'Taylor',
                    'email' => 'taylor@laravel.com',
                    'framework' => 'Laravel',
                ], array_flip($this->attributes));
            }

            public function cacheKey(): string
            {
                return "user:{$this->id}:".implode(',', $this->attributes);
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

    public function test_it_can_set_a_ttl() {}
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
