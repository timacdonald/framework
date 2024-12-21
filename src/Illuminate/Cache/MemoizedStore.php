<?php

namespace Illuminate\Cache;

use Illuminate\Contracts\Cache\Store;

class MemoizedStore implements Store
{
    /**
     * The memoized cache values.
     *
     * @var array<string, mixed>
     */
    protected $cache = [];

    /**
     * Create a new memoized cache instance.
     *
     * @param  string  $name
     * @param  \Illuminate\Cache\Repository  $repository
     */
    public function __construct(
        protected $name,
        protected $repository,
    ) {
        //
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        if (array_key_exists($this->prefix($key), $this->cache)) {
            return $this->cache[$this->prefix($key)];
        }

        return $this->cache[$this->prefix($key)] = $this->repository->get($key);
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @return array
     */
    public function many(array $keys)
    {
        $memoized = [];
        $retrieved = [];
        $missing = [];

        foreach ($keys as $key) {
            if (array_key_exists($this->prefix($key), $this->cache)) {
                $memoized[$key] = $this->cache[$this->prefix($key)];
            } else {
                $missing[] = $key;
            }
        }

        if (count($missing) > 0) {
            $retrieved = tap($this->repository->many($missing), $this->memoize(...));
        }

        return [
            ...$memoized,
            ...$retrieved,
        ];
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
        return tap($this->repository->put($key, $value, $seconds), function ($result) use ($key, $value) {
            if ($result) {
                $this->memoize([$key => $value]);
            }
        });
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  int  $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        return tap($this->repository->putMany($values, $seconds), function ($result) use ($values) {
            if ($result) {
                $this->memoize($values);
            }
        });
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
        return tap($this->repository->increment($key, $value), function ($result) use ($key) {
            if (is_int($result)) {
                $this->memoize([$key => $result]);
            }
        });
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
        return tap($this->repository->decrement($key, $value), function ($result) use ($key) {
            if (is_int($result)) {
                $this->memoize([$key => $result]);
            }
        });
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
        return tap($this->repository->forever($key, $value), function ($result) use ($key, $value) {
            if ($result) {
                $this->memoize([$key => $value]);
            }
        });
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        return tap($this->repository->forget($key), function ($result) use ($key) {
            if ($result) {
                unset($this->cache[$this->prefix($key)]);
            }
        });
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        return tap($this->repository->flush(), function ($result) {
            if ($result) {
                $this->cache = [];
            }
        });
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->repository->getPrefix();
    }

    /**
     * Prefix the given key.
     *
     * @param  string  $key
     * @return string
     */
    protected function prefix($key)
    {
        return $this->getPrefix().$key;
    }

    /**
     * Memoize the given values.
     *
     * @param  array  $values
     * @return void
     */
    protected function memoize($values)
    {
        $this->cache = [
            ...$this->cache,
            ...collect($values)->mapWithKeys(fn ($value, $key) => [
                $this->prefix($key) => is_null($value) ? $value : (string) $value,
            ]),
        ];
    }
}
