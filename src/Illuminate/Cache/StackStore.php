<?php

namespace Illuminate\Cache;

use Illuminate\Contracts\Cache\RetrievesTTL;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StackStore implements Store
{
    /**
     * @param  Collection<int, Repository>  $repositories
     */
    public function __construct(
        protected Collection $repositories
    ) {
        if ($repositories->isEmpty()) {
            throw new RuntimeException('There must be at least one driver in the stack.');
        }

        $supported = $this->repositories
            ->skip(1)
            ->every(fn ($repository) => $repository->getStore() instanceof RetrievesTTL);

        if (! $supported) {
            throw new RuntimeException('Nested stack stores must implement ['.RetrievesTTL::class.'].');
        }
    }
    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        foreach ($this->repositories as $index => $repository) {
            $value = $repository->get($key);

            if ($value === null) {
                continue;
            }

            if ($index === 0) {
                return $value;
            }

            /** @var Repository&RetrievesTTL $repository */

            $ttl = $repository->ttl($key);

            if ($ttl === null) {
                $value = null;
                continue;
            }

            break;
        }

        if ($value === null) {
            return null;
        }

        foreach ($this->repositories->take($index) as $repository) {
            if ($ttl->isForever()) {
                $repository->forever($key, $value);
            } else {
                $repository->put($key, $value, $ttl->inSeconds());
            }
        }

        return $value;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @param  array  $keys
     * @return array
     */
    public function many(array $keys)
    {
        // TODO
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        return $this->repositories
            ->map->put($key, $value, $seconds)
            ->every(fn ($result) => $result);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  array  $values
     * @param  int  $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        return $this->repositories
            ->map->putMany($values, $seconds)
            ->every(fn ($result) => $result);
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        // Should this increment the slowest store and then backfill the
        // others to keep the value consistent?
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        // Should this increment the slowest store and then backfill the
        // others to keep the value consistent?
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return bool
     */
    public function forever($key, $value)
    {
        return $this->repositories
            ->map->forever($key, $value)
            ->every(fn ($result) => $result);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        return $this->repositories
            ->map->forget($key)
            ->every(fn ($result) => $result);
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        return $this->repositories
            ->map->flush()
            ->every(fn ($result) => $result);
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        throw new RuntimeException('The stack driver does not support a prefix.');
    }
}
