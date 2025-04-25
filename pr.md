# Cache Objects

## What

This PR introduces a new concept to the framework known as Cache Objects. Cache Objects can house caching logic for improved re-use throughout your application.

Cache objects can also be kept warm via the scheduler. We introduced `Cache::flexible` to remove empty cache hits in favour of stale cache hits. With cache object warming via the scheduler, you can now remove stale and empty cache hits in favour of a always-warm cache.

## Why

I often find myself duplicating cache interactions throughout a code base.

```php
<?php

$username = Cache::remember("github-username:{$user->github_id}", now()->addHours(24), function () use ($user) {
    return Http::withToken($user->github_token)
        ->get('https://github.com/api/...')
        ->json('data.username');
});

// somewhere else in the code base...

$username = Cache::remember("github-username:{$user->github_id}", now()->addHour(), function () use ($user) {
    return Http::withToken($user->github_token)
        ->get('https://github.com/api/...')
        ->throw()
        ->json('data.username');
});
```

The closure managed to get out of sync. I'll extract a class to house the callback logic to ensure it stays in sync.

```php
<?php

class GitHubUsername
{
    public function __construct(protected $user)
    {
        //
    }

    public function resolve()
    {
        return Http::withToken($this->user->github_token)
            ->get('https://github.com/api/...')
            ->throw()
            ->json('data.username');
    }
}

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->github_id}",
    now()->addHours(24),
    fn () => $resolver->resolve(), // does not accept a callable
);

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->github_id}",
    now()->addHour(),
    fn () => $resolver->resolve(), // does not accept a callable
);
```

The TTL is out of sync. I'll extract that.

```php
<?php

class GitHubUsername
{
    public function __construct(protected $user)
    {
        //
    }

    public function ttl()
    {
        return now()->addHours(24);
    }

    public function resolve()
    {
        return Http::withToken($this->user->github_token)
            ->get('https://github.com/api/...')
            ->throw()
            ->json('data.username');
    }
}

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->github_id}",
    $resolver->ttl(),
    fn () => $resolver->resolve(),
);

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->github_id}",
    $resolver->ttl(),
    fn () => $resolver->resolve(),
);
```

Would be silly not to extract the key at this point, especially because it is so tied to the user and the cached value...

```php
<?php

class GitHubUsername
{
    public function __construct(protected $user)
    {
        //
    }

    public function key()
    {
        return "github-username:{$this->user->github_id}";
    }

    public function ttl()
    {
        return now()->addHours(24);
    }

    public function resolve()
    {
        return Http::withToken($this->user->github_token)
            ->get('https://github.com/api/...')
            ->throw()
            ->json('data.username');
    }
}

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    $resolver->key(),
    $resolver->ttl(),
    fn () => $resolver->resolve(),
);

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    $resolver->key(),
    $resolver->ttl(),
    fn () => $resolver->resolve(),
);
```

I'm starting to smell primitive obsession; also, when I see this code, it screams out for a framework-first abstraction.

So I built one.

```php
<?php

class GitHubUsername
{
    public function __construct(protected $user)
    {
        //
    }

    public function key()
    {
        return "github-username:{$this->user->github_id}";
    }

    public function ttl()
    {
        return now()->addHours(24);
    }

    public function resolve()
    {
        return Http::withToken($this->user->github_token)
            ->get('https://github.com/api/...')
            ->throw()
            ->json('data.username');
    }
}

// --- //

$username = Cache::value(new GitHubUsername($user));

// somewhere else in the codebase...

$username = Cache::value(new GitHubUsername($user));
```

A developer-facing cache object API is only half the story. With first-party Cache Objects, Laravel can offer the ability to keep the cache objects warm via the scheduler. The following example will warm the cache with all the U.S. AWS regions and also warm the GitHub username cache for high-traffic users.

```php
<?php

Schedule::warm([
    new AwsRegions('us'),
    fn () => User::highTraffic()->get()->mapInto(GithubUsername::class);
])->everyHour();
```

## Cache Objects

The follow expresses the basics of a cache object: a cache key and a `resolve` method.

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache key.
     *
     * @var string
     */
    public $key = 'laravel-website';

    /**
     * Resolve the value to store in the cache.
     *
     * @return mixed
     */
    public function resolve()
    {
        return Http::get('https://laravel.com')
            ->throw()
            ->body();
    }
}

$html = Cache::value(new LaravelWebsite);
```

The `resolve` method is called by the container allowing method injection:

```php
<?php

namespace App\Cache;

use Illuminate\Http\Client\Factory;

class LaravelWebsite
{
    /**
     * The cache key.
     *
     * @var string
     */
    public $key = 'laravel-website';

    /**
     * Resolve the value to store in the cache.
     *
     * @return mixed
     */
    public function resolve(Factory $http)
    {
        return $http->get('https://laravel.com')
            ->throw()
            ->body();
    }
}
```

- [ ] Method injection
- [ ] Casting ints to string?
