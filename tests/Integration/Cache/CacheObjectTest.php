<?php

namespace Illuminate\Tests\Integration\Cache;

use Attribute;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use SplObjectStorage;
use Stringable;
use WeakMap;

/**
 * Does using `app()->call($c->hydrate(...), ['value' => $value])` feel clunky
 * because you _must_ call the cached value `$value`?
 * How can I keep my most active users cached?
 * What does it look like in the schedule?
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

        $resolveMethodOrProperty = fn ($cacheable, $method) => method_exists($cacheable, $method)
            ? app()->call($cacheable->{$method}(...))
            : $cacheable->{$method} ?? null;

        $resolveKey = fn ($cacheable) => $resolveMethodOrProperty($cacheable, 'key');

        $resolveTtl = fn ($cacheable) => $resolveMethodOrProperty($cacheable, 'ttl');

        $resolveStore = fn ($cacheable) => $resolveMethodOrProperty($cacheable, 'store');

        $hydrate = function ($cacheable, $value) {
            if (! method_exists($cacheable, 'hydrate')) {
                return $value;
            }

            $parameters = (new ReflectionMethod($cacheable, 'hydrate'))->getParameters();

            if ($parameters === []) {
                return $cacheable->hydrate($value);
            }

            return app()->call($cacheable->hydrate(...), [$parameters[0]->getName() => $value]);
        };

        $memo = collect([]);
        Cache::macro('value', fn ($cacheable) => Cache::values([$cacheable])[0]);

        Cache::macro('values', function ($cacheables) use ($resolveKey, $resolveTtl, $resolveStore, $hydrate, $memo) {
            $order = [];
            $cacheables = collect($cacheables);

            $storeMappedResults = $cacheables
                ->groupBy(function ($cacheable) use ($resolveStore) {
                    $store = $resolveStore($cacheable);

                    if ($store  === null) {
                        $store = Cache::getDefaultDriver();
                    }

                    if ($cacheable instanceof RepositoryAware) {
                        $cacheable->setRepository(Cache::store($store));
                    }

                    return $store;
                })
                ->map(function ($cacheables, $store) use ($resolveKey, $resolveTtl, $hydrate, &$order, $memo) {
                    $keyMap = $cacheables
                        ->mapWithKeys(function ($cacheable) use ($resolveKey, &$order, $store) {
                            $key = (string) $resolveKey($cacheable);

                            if ($key === '') {
                                throw new RuntimeException('Cache object must have a key defined');
                            }

                            $order[] = [$store, $key];

                            return [$key => $cacheable];
                        });

                    $memoized = collect($memo[$store] ?? [])
                        ->only($keyMap->keys())
                        ->map(fn ($value) => value($value));

                    $flexibleTtlMap = new WeakMap;

                    $ttlGrouped = $keyMap
                        ->except($memoized->keys())
                        ->groupBy(function ($cacheable) use ($resolveTtl, $flexibleTtlMap) {
                            $ttl = $resolveTtl($cacheable);

                            if (is_array($ttl)) {
                                $flexibleTtlMap[$cacheable] = $ttl;

                                return 'flexible';
                            }

                            return $ttl;
                        }, preserveKeys: true);

                    [$flexible, $ttlGrouped] = [
                        $ttlGrouped->get('flexible', collect()),
                        $ttlGrouped->except('flexible'),
                    ];

                    $flexiblelyCached = $flexible->map(function ($cacheable, $key) use ($store, $flexibleTtlMap) {
                        return Cache::store($store)
                            ->flexible($key, $flexibleTtlMap[$cacheable], app()->wrap($cacheable->resolve(...)));
                    });

                    // TODO Don't do this if there aren't any to retrieve!
                    $cached = collect(Cache::store($store)
                        ->many($keyMap->except([
                            ...$memoized->keys(),
                            ...$flexiblelyCached->keys(),
                        ])->keys()->all()))
                        ->reject(fn ($value) => $value === null);

                    $resolved = $ttlGrouped
                        ->flatMap(function ($ttlGroup, $ttl) use ($store, $memo, $flexibleTtlMap, $memoized, $cached, $flexiblelyCached) {
                            $ttlGroup = $ttlGroup->except([
                                ...$memoized->keys(),
                                // TODO I don't think flexibely cached can end up here
                                ...$flexiblelyCached->keys(),
                                ...$cached->keys(),
                            ]);

                            $ttl = $ttl === '' ? null : $ttl;

                            $values = $ttlGroup
                                ->map(fn ($cacheable, $key) => app()->call($cacheable->resolve(...)));


                            if ($values->containsOneItem()) {
                                Cache::store($store)->put($values->keys()->first(), $values->first(), $ttl);
                            } else {
                                Cache::store($store)->putMany($values->all(), $ttl);
                            }

                            return $values;
                        });

                    return collect([...$cached, ...$flexiblelyCached, ...$resolved])
                        ->map(function ($value, $key) use ($hydrate, $store, $keyMap, $memo) {
                            $value = $hydrate($keyMap[$key], $value);

                            if ((new ReflectionClass($keyMap[$key]))->getAttributes(Memoize::class) !== []){
                                $memo[$store] ??= [];
                                $memo[$store] = [
                                    ...$memo[$store],
                                    $key => $value,
                                ];
                            }

                            return value($value);
                        })->merge($memoized);
                });

            $results = [];

            foreach ($order as [$store, $key]) {
                $results[] = $storeMappedResults[$store][$key];
            }

            return $results;
        });
        Cache::macro('warm', function ($cacheable, $value = null) use ($resolveKey, $resolveTtl, $resolveStore, $hydrate, $memo) {
            $result = true;
            $cacheables = Collection::wrap($cacheable);

            $storeMappedResults = $cacheables
                ->groupBy(function ($cacheable) use ($resolveStore) {
                    $store = $resolveStore($cacheable);

                    if ($store  === null) {
                        $store = Cache::getDefaultDriver();
                    }

                    if ($cacheable instanceof RepositoryAware) {
                        $cacheable->setRepository(Cache::store($store));
                    }

                    return $store;
                })
                ->map(function ($cacheables, $store) use ($resolveKey, $resolveTtl, $hydrate, $memo) {
                    $keyMap = $cacheables
                        ->mapWithKeys(function ($cacheable) use ($resolveKey, $store) {
                            $key = (string) $resolveKey($cacheable);

                            if ($key === '') {
                                throw new RuntimeException('Cache object must have a key defined');
                            }

                            return [$key => $cacheable];
                        });

                    if ($memo->has($store)) {
                        $keyMap->each(function ($_, $key) use (&$memo, $store) {
                            unset($memo[$store][$key]);
                        });
                    }

                    $flexibleTtlMap = new WeakMap;

                    $ttlGrouped = $keyMap
                        ->groupBy(function ($cacheable) use ($resolveTtl, $flexibleTtlMap) {
                            $ttl = $resolveTtl($cacheable);

                            if (is_array($ttl)) {
                                $flexibleTtlMap[$cacheable] = $ttl;

                                return 'flexible';
                            }

                            return $ttl;
                        }, preserveKeys: true);

                    [$flexible, $ttlGrouped] = [
                        $ttlGrouped->get('flexible', collect()),
                        $ttlGrouped->except('flexible'),
                    ];

                    // TODO capture result
                    $flexible->map(function ($cacheable, $key) use ($store, $flexibleTtlMap) {
                        Cache::store($store)->forget($key);

                        return Cache::store($store)
                            ->flexible($key, $flexibleTtlMap[$cacheable], app()->wrap($cacheable->resolve(...)));
                    });

                    $ttlGrouped
                        ->flatMap(function ($ttlGroup, $ttl) use ($store, $memo, $flexibleTtlMap, $flexible) {
                            $ttl = $ttl === '' ? null : $ttl;

                            $values = $ttlGroup
                                ->map(fn ($cacheable, $key) => app()->call($cacheable->resolve(...)));

                            if ($values->containsOneItem()) {
                                Cache::store($store)->put($values->keys()->first(), $values->first(), $ttl);
                            } else {
                                Cache::store($store)->putMany($values->all(), $ttl);
                            }

                            return $values;
                        });

                    return true;
                });

            return true;
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
        Event::fake([RetrievingManyKeys::class, WritingManyKeys::class, KeyWritten::class]);

        $result = Cache::values([
            $factory('name.0', 'Taylor', 5),
            $factory('name.1', 'Tim', 5),
            $factory('name.2', 'Jess', 10),
            $factory('name.3', 'Ryuta', 10),
            $factory('name.4', 'Sabrina', 5),
            $factory('name.5', 'Jeremy', null),
            $factory('name.6', 'Phillip', null),
        ]);

        Event::assertDispatched(RetrievingManyKeys::class, 1);
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === [
            'name.0', 'name.1', 'name.2', 'name.3', 'name.4', 'name.5', 'name.6',
        ]);
        Event::assertDispatched(WritingManyKeys::class, 2);
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'name.0', 'name.1', 'name.4',
        ] && $event->seconds === 5);
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'name.2', 'name.3',
        ] && $event->seconds === 10);
        // Writing many keys is not possible via a single call when ttl is
        // `null`.  Need to check for multiple writes instead...
        Event::assertDispatched(fn (KeyWritten $event) => $event->key === 'name.5' && $event->seconds === null);
        Event::assertDispatched(fn (KeyWritten $event) => $event->key === 'name.6' && $event->seconds === null);
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

        $this->travel(10)->seconds();

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

    public function test_it_can_name_first_hydrate_parameter_anything()
    {

        $this->app->instance(MyTestService::class, new MyTestService('Otwell'));
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function hydrate($foo, MyTestService $service)
            {
                return "{$foo} {$service->value}";
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor Otwell', $result);
        $this->assertSame('Taylor', $valueInCache);
    }

    public function test_it_always_passes_the_value_even_when_no_parameters_specified()
    {
        $object = new class
        {
            public $key = 'name';

            public function resolve()
            {
                return 'Taylor';
            }

            public function hydrate()
            {
                $value = func_get_args()[0];

                return "{$value} Otwell";
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

            public function resolve()
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

            public function resolve()
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

            public function resolve()
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

    public function test_it_can_have_access_to_the_cache_repository()
    {
        $object = new class implements RepositoryAware
        {
            private $repository;

            public function setRepository(Repository $repository): void
            {
                $this->repository = $repository;
            }

            public function key()
            {
                return $this->repository->get('key');
            }

            public function resolve()
            {
                return $this->repository->get('value');
            }

            public function ttl()
            {
                return $this->repository->get('ttl');
            }
        };
        Cache::putMany([
            'key' => 'name',
            'value' => 'Taylor',
            'ttl' => 10,
        ]);

        $result = Cache::value($object);
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertEqualsWithDelta(10, $ttl, 1);
        $this->assertSame($result, 'Taylor');
    }

    public function test_it_can_specify_the_cache_store()
    {
        $object = new class implements RepositoryAware
        {
            public $repository;
            public $key = 'name';

            public function store()
            {
                return 'array';
            }

            public function setRepository(Repository $repository): void
            {
                $this->repository = $repository;
            }

            public function resolve()
            {
                return 'Taylor';
            }
        };

        $result = Cache::value($object);
        $valueInDefaultStore = Cache::get('name');
        $valueInArrayStore = Cache::store('array')->get('name');

        $this->assertSame($result, 'Taylor');
        $this->assertSame($valueInDefaultStore, null);
        $this->assertSame($valueInArrayStore, 'Taylor');
        $this->assertSame($object->repository, Cache::store('array'));
    }

    public function test_it_can_retrieve_values_across_multiple_stores()
    {
        $factory = fn ($key, $store) => new class($key, $store)
        {
            public function __construct(
                public $key,
                public $store,
            )
            {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::store('array')->put('name', 'Taylor');
        Cache::store('redis')->put('name', 'Tim');

        $result = Cache::values([
            $factory('name', 'array'),
            $factory('name', 'redis'),
        ]);

        $this->assertSame($result, ['Taylor', 'Tim']);
    }

    public function test_it_groups_retrieval_of_values_across_multiple_stores_into_single_call_per_store()
    {
        $factory = fn ($store, $key) => new class($store, $key)
        {
            public function __construct(
                public $store,
                public $key,
            )
            {
                //
            }

            public function resolve()
            {
                //
            }
        };
        Cache::store('array')->putMany(['name.0' => 'Taylor', 'name.1' => 'Otwell']);
        Cache::store('redis')->putMany(['name.0' => 'Tim', 'name.1' => 'MacDonald']);

        $result = Cache::values([
            $factory('array', 'name.0'),
            $factory('array', 'name.1'),
            $factory('redis', 'name.0'),
            $factory('redis', 'name.1'),
        ]);

        $this->assertSame($result, ['Taylor', 'Otwell', 'Tim', 'MacDonald']);
    }

    public function test_it_groups_cache_writes_across_multiple_stores_into_single_call_per_store_per_ttl()
    {
        $factory = fn ($store, $key, $value, $ttl) => new class($store, $key, $value, $ttl)
        {
            public function __construct(
                public $store,
                public $key,
                public $value,
                public $ttl,
            )
            {
                //
            }

            public function resolve()
            {
                return $this->value;
            }
        };

        Event::fake([RetrievingManyKeys::class, WritingManyKeys::class, KeyWritten::class]);

        $result = Cache::values([
            $factory('array', 'framework', 'Laravel', 10),
            $factory('array', 'language', 'PHP', 10),
            $factory('array', 'end', 'back', null),
            $factory('redis', 'framework', 'Vue', 9),
            $factory('redis', 'language', 'JavaScript', 9),
            $factory('redis', 'end', 'front', null),
        ]);

        $this->assertSame($result, [
            'Laravel', 'PHP', 'back',
            'Vue', 'JavaScript', 'front',
        ]);
        Event::assertDispatched(RetrievingManyKeys::class, 2);
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === [
            'framework', 'language', 'end',
        ] && $event->storeName === 'array');
        Event::assertDispatched(fn (RetrievingManyKeys $event) => $event->keys === [
            'framework', 'language', 'end',
        ] && $event->storeName === 'redis');
        Event::assertDispatched(WritingManyKeys::class, 2);
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'framework', 'language',
        ] && $event->seconds === 10 && $event->storeName === 'array');
        Event::assertDispatched(fn (WritingManyKeys $event) => $event->keys === [
            'framework', 'language',
        ] && $event->seconds === 9 && $event->storeName === 'redis');
        // Writing many keys is not possible via a single call when ttl is
        // `null`.  Need to check for multiple writes instead...
        Event::assertDispatched(fn (KeyWritten $event) => $event->key === 'end' && $event->seconds === null && $event->storeName === 'array');
        Event::assertDispatched(fn (KeyWritten $event) => $event->key === 'end' && $event->seconds === null && $event->storeName === 'redis');
    }

    public function test_it_can_memoize_resolved_value()
    {
        $this->freezeTime();
        $object = new MemoizedObject(
            key: 'time',
            resolve: fn () => 'Resolve: '.now()->getTimestamp(),
            hydrate: fn ($value) => $value.' Hydrate: '.now()->getTimestamp(),
        );

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        $this->assertSame('Resolve: '.now()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $result);
        $this->assertSame('Resolve: '.now()->getTimestamp(), $valueInCache);

        $this->travel(1)->minute();
        Cache::forget('time');

        $result = Cache::value($object);
        $valueInCache = Cache::get('time');

        $this->assertSame('Resolve: '.now()->subMinute()->getTimestamp().' Hydrate: '.now()->subMinute()->getTimestamp(), $result);
        $this->assertNull($valueInCache);
    }

    public function test_value_is_memoized_across_instances_per_store()
    {
        $this->freezeTime();
        $factory = fn ($store) => new MemoizedObject(
            key: 'time',
            resolve: fn () => 'Resolve: '.now()->getTimestamp(),
            hydrate: fn ($value) => $value.' Hydrate: '.now()->getTimestamp(),
            store: $store,
        );

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');

        $this->assertSame('Resolve: '.now()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $arrayStoreResult);
        $this->assertSame('Resolve: '.now()->getTimestamp(), $valueInArrayStore);

        $this->travel(1)->minute();
        Cache::store('array')->forget('time');

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');
        $redisStoreResult = Cache::value($factory('redis'));
        $valueInRedisStore = Cache::store('redis')->get('time');

        $this->assertSame('Resolve: '.now()->subMinute()->getTimestamp().' Hydrate: '.now()->subMinute()->getTimestamp(), $arrayStoreResult);
        $this->assertNull($valueInArrayStore);
        $this->assertSame('Resolve: '.now()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $redisStoreResult);
        $this->assertSame('Resolve: '.now()->getTimestamp(), $valueInRedisStore);

        $this->travel(1)->minute();
        Cache::store('redis')->forget('time');

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');
        $redisStoreResult = Cache::value($factory('redis'));
        $valueInRedisStore = Cache::store('redis')->get('time');

        $this->assertSame('Resolve: '.now()->subMinutes(2)->getTimestamp().' Hydrate: '.now()->subMinutes(2)->getTimestamp(), $arrayStoreResult);
        $this->assertNull($valueInArrayStore);
        $this->assertSame('Resolve: '.now()->subMinute()->getTimestamp().' Hydrate: '.now()->subMinute()->getTimestamp(), $redisStoreResult);
        $this->assertNull($valueInRedisStore);
    }

    public function test_can_memoize_resolve_but_not_hydrate_by_returning_a_closure_from_hydrate()
    {
        $this->freezeTime();
        $factory = fn ($store) => new MemoizedObject(
            key: 'time',
            resolve: fn () => 'Resolve: '.now()->getTimestamp(),
            hydrate: fn ($value) => fn () => $value.' Hydrate: '.now()->getTimestamp(),
            store: $store,
        );

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');

        $this->assertSame('Resolve: '.now()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $arrayStoreResult);
        $this->assertSame('Resolve: '.now()->getTimestamp(), $valueInArrayStore);

        $this->travel(1)->minute();
        Cache::store('array')->forget('time');

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');
        $redisStoreResult = Cache::value($factory('redis'));
        $valueInRedisStore = Cache::store('redis')->get('time');

        $this->assertSame('Resolve: '.now()->subMinute()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $arrayStoreResult);
        $this->assertNull($valueInArrayStore);
        $this->assertSame('Resolve: '.now()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $redisStoreResult);
        $this->assertSame('Resolve: '.now()->getTimestamp(), $valueInRedisStore);

        $this->travel(1)->minute();
        Cache::store('redis')->forget('time');

        $arrayStoreResult = Cache::value($factory('array'));
        $valueInArrayStore = Cache::store('array')->get('time');
        $redisStoreResult = Cache::value($factory('redis'));
        $valueInRedisStore = Cache::store('redis')->get('time');

        $this->assertSame('Resolve: '.now()->subMinutes(2)->getTimestamp().' Hydrate: '.now()->getTimestamp(), $arrayStoreResult);
        $this->assertNull($valueInArrayStore);
        $this->assertSame('Resolve: '.now()->subMinute()->getTimestamp().' Hydrate: '.now()->getTimestamp(), $redisStoreResult);
        $this->assertNull($valueInRedisStore);
    }

    public function test_closure_returned_from_hydrate_does_not_receive_args()
    {
        $this->freezeTime();
        $factory = fn ($store) => new MemoizedObject(
            key: 'time',
            resolve: fn () => 'Resolve: '.now()->getTimestamp(),
            hydrate: fn ($value) => fn (...$args) => $args,
            store: $store,
        );

        $result = Cache::value($factory('array'));
        $this->assertSame($result, []);

        $result = Cache::value($factory('array'));
        $this->assertSame($result, []);
    }

    public function test_it_can_memoize_per_instance_via_once_helper()
    {
        $this->freezeTime();
        $factory = fn () => new class
        {
            public $key = 'time';

            public function resolve()
            {
                return (string) now()->getTimestamp();
            }

            public function hydrate($value)
            {
                return once(fn () => "Resolved: $value Hydrated: ".now()->getTimestamp());
            }
        };
        $first = $factory();
        $second = $factory();

        $firstValue = Cache::value($first);

        $this->assertSame('Resolved: '.now()->getTimestamp().' Hydrated: '.now()->getTimestamp(), $firstValue);

        $this->travel(5)->seconds();

        $firstValue = Cache::value($first);
        $secondValue = Cache::value($second);

        $this->assertSame('Resolved: '.now()->subSeconds(5)->getTimestamp().' Hydrated: '.now()->subSeconds(5)->getTimestamp(), $firstValue);
        $this->assertSame('Resolved: '.now()->subSeconds(5)->getTimestamp().' Hydrated: '.now()->getTimestamp(), $secondValue);

        $this->travel(5)->seconds();

        $firstValue = Cache::value($first);
        $secondValue = Cache::value($second);

        $this->assertSame('Resolved: '.now()->subSeconds(10)->getTimestamp().' Hydrated: '.now()->subSeconds(10)->getTimestamp(), $firstValue);
        $this->assertSame('Resolved: '.now()->subSeconds(10)->getTimestamp().' Hydrated: '.now()->subSeconds(5)->getTimestamp(), $secondValue);
    }

    public function test_it_can_be_warmed()
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
                throw new RuntimeException(__FUNCTION__);
            }
        };

        $result = Cache::warm($object);
        $valueInCache = Cache::get('name');

        $this->assertSame('Taylor', $valueInCache);
        $this->assertTrue($result);
    }

    public function test_it_can_warm_multiple_instances()
    {
        $factory = fn ($key, $value) => new class($key, $value)
        {
            public function __construct(
                public $key,
                public $value,
            ) {
                //
            }
            public function resolve()
            {
                return $this->value;
            }

            public function hydrate($value)
            {
                throw new RuntimeException(__FUNCTION__);
            }
        };

        $result = Cache::warm([
            $factory('name.0', 'Taylor'),
            $factory('name.1', 'Otwell'),
        ]);
        $valuesInCache = Cache::many(['name.0', 'name.1']);

        $this->assertSame(['name.0' => 'Taylor', 'name.1' => 'Otwell'], $valuesInCache);
        $this->assertTrue($result);
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

    public function test_it_can_use_flexible()
    {
        $this->freezeTime();
        $object = new class
        {
            public $key = 'name';

            public $ttl = [7, 12];

            public $resolved = 0;

            public function resolve()
            {
                $this->resolved++;

                return "Resolved {$this->resolved}";
            }
        };

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');

        $this->assertCount(0, defer());
        $this->assertSame($created, (string) now()->getTimestamp());
        $this->assertSame('Resolved 1', $result);
        $this->assertSame('Resolved 1', $valueInCache);

        $this->travel(5)->seconds();

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');

        $this->assertCount(0, defer());
        $this->assertSame($created, (string) now()->subSeconds(5)->getTimestamp());
        $this->assertSame('Resolved 1', $result);
        $this->assertSame('Resolved 1', $valueInCache);

        $this->travel(5)->seconds();

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');

        $this->assertCount(1, defer());
        $this->assertSame($created, (string) now()->subSeconds(10)->getTimestamp());
        $this->assertSame('Resolved 1', $result);
        $this->assertSame('Resolved 1', $valueInCache);

        $this->travel(5)->seconds();

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertCount(1, defer());
        $this->assertSame($created, (string) now()->subSeconds(15)->getTimestamp());
        $this->assertSame('Resolved 1', $result);
        $this->assertSame('Resolved 1', $valueInCache);

        defer()->invoke();

        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertCount(0, defer());
        $this->assertSame($created, (string) now()->getTimestamp());
        $this->assertSame('Resolved 2', $valueInCache);

        $result = Cache::value($object);
        $valueInCache = Cache::get('name');
        $created = Cache::get('illuminate:cache:flexible:created:name');
        $ttl = Cache::store()->connection()->ttl(Cache::store()->getPrefix().'name');

        $this->assertCount(0, defer());
        $this->assertSame($created, (string) now()->getTimestamp());
        $this->assertSame('Resolved 2', $valueInCache);
        $this->assertSame('Resolved 2', $result);
    }

    public function test_warming_flushes_memoized_value()
    {
        $this->markTestIncomplete();
    }

    public function test_it_can_be_nicely_tied_into_eloquent_events_to_stay_up_to_date()
    {
        // static function updated($model)
        // {
        //      Cache::warm(new CachedUser($model->id), $model);
        // }
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

interface RepositoryAware
{
    public function setRepository(Repository $repository): void;
}

#[Attribute]
class Memoize
{
    //
}

#[Memoize]
class MemoizedObject
{
    public function __construct(
        public $key,
        public $resolve,
        public $hydrate,
        public $store = null,
    ) {
        //
    }

    public function resolve()
    {
        return call_user_func($this->resolve);
    }

    public function hydrate($value)
    {
        return call_user_func($this->hydrate, $value);
    }
}
