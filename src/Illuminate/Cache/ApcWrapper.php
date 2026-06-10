<?php

namespace Illuminate\Cache;

class ApcWrapper
{
    /**
     * Get an item from the cache.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        $fetchedValue = apcu_fetch($key, $success);

        return $success ? $fetchedValue : null;
    }

    /**
     * Store an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        return apcu_store($key, $value, $seconds);
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string|array  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function add($key, $value, $seconds)
    {
        return apcu_add($key, $value, $seconds);
    }

    /**
     * Atomically fetch or generate a cache value.
     *
     * @param  string  $key
     * @param  callable  $callback
     * @param  ?int  $ttl
     */
    public function entry($key, $callback, $ttl)
    {
        return apc_entry($key, $callback, $ttl);
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|false
     */
    public function increment($key, $value)
    {
        return apcu_inc($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|false
     */
    public function decrement($key, $value)
    {
        return apcu_dec($key, $value);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function delete($key)
    {
        return apcu_delete($key);
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        return apcu_clear_cache();
    }
}
