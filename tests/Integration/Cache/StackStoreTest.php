<?php

namespace Illuminate\Tests\Integration\Cache;

use DateTime;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\StackStore;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase;

#[WithMigration('cache')]
class StackStoreTest extends DatabaseTestCase
{
    use InteractsWithRedis;

    /** {@inheritdoc} */
    #[\Override]
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            $this->setUpRedis();

            Cache::store('file')->flush();
            Cache::store('database')->flush();
            Cache::store('redis')->flush();
            Config::set('cache.stores.stack', [
                'driver' => 'stack',
            ]);
            Cache::extend('stack', function () {
                return new StackStore(new Collection([
                    Cache::store('array'),
                    Cache::store('redis'),
                    Cache::store('database'),
                ]));
            });
        });

        $this->beforeApplicationDestroyed(function () {
            $this->tearDownRedis();
        });

        parent::setUp();
    }

    public function test_it_traverses_cache_stores_to_retrieve_value_via_get()
    {
        Cache::store('database')->put('name', 'Taylor');

        $value = Cache::driver('stack')->get('name');

        $this->assertSame('Taylor', $value);
    }

    public function test_it_populates_lower_stores_with_retrieved_value()
    {
        Cache::store('database')->put('name', 'Taylor');

        $stackValue = Cache::driver('stack')->get('name');
        $storeValues = [
            Cache::driver('array')->get('name'),
            Cache::driver('redis')->get('name'),
            Cache::driver('database')->get('name'),
        ];

        $this->assertSame('Taylor', $stackValue);
        $this->assertSame(['Taylor', 'Taylor', 'Taylor'], $storeValues);
    }

    public function test_it_uses_ttl_from_source_cache()
    {
        $this->freezeTime();
        Cache::store('database')->put('name', 'Taylor', 60);

        $stackValue = Cache::driver('stack')->get('name');
        $storeTtls = [
            Cache::store('array')->ttl('name')?->inSeconds(),
            Cache::store('database')->ttl('name')?->inSeconds(),
            Cache::store("redis")->ttl('name')?->inSeconds(),
        ];

        $this->assertSame('Taylor', $stackValue);
        $this->assertSame([60, 60, 60], $storeTtls);
    }

    public function test_it_can_find_the_value_in_the_first_store()
    {
        Config::set('cache.stores.stack', [
            'driver' => 'stack',
        ]);
        Cache::extend('stack', function () {
            return new StackStore(new Collection([
                Cache::store('redis'),
                Cache::store('database'),
            ]));
        });
        $prefix = Cache::driver('redis')->getPrefix();

        Cache::store('redis')->put('name', 'Taylor', 60);

        $value = Cache::driver('stack')->get('name');
        $databaseTTL = DB::table('cache')->value('expiration');
        $redisTTL = Cache::store("redis")->connection()->ttl("laravel_cache_name");

        $this->assertSame('Taylor', $value);
        $this->assertSame(null, $databaseTTL);
        $this->assertSame(60, $redisTTL);

    }
}
