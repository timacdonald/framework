# Cache Objects

## What

This PR introduces a new concept to the framework known as Cache Objects. Cache Objects can house caching logic for improved re-use throughout your application.

Cache objects can also be kept warm via the scheduler. We introduced `Cache::flexible` to remove empty cache hits in favour of stale cache hits. With cache object warming via the scheduler, you can now remove stale and empty cache hits in favour of a always-warm cache.

## Why

I often find myself duplicating cache interactions throughout a code base.

```php
<?php

$username = Cache::remember("github-username:{$user->id}", now()->addHours(24), function () use ($user) {
    return Http::withToken($user->github_token)
        ->get('https://github.com/api/...')
        ->json('data.username');
});

// somewhere else in the code base...

$username = Cache::remember("github-username:{$user->id}", now()->addHour(), function () use ($user) {
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
    "github-username:{$user->id}",
    now()->addHours(24),
    fn () => $resolver->resolve(), // does not accept a callable
);

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->id}",
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
    "github-username:{$user->id}",
    $resolver->ttl(),
    fn () => $resolver->resolve(),
);

// --- //

$resolver = new GitHubUsername($user);

$username = Cache::remember(
    "github-username:{$user->id}",
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
        return "github-username:{$this->user->id}";
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
        return "github-username:{$this->user->id}";
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

// --- //

$html = Cache::value(new LaravelWebsite);
```

The `resolve` method is called by the container allowing method injection:

```php
<?php

namespace App\Cache;

use Illuminate\Http\Client\Factory;

class LaravelWebsite
{
    // ...

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

The cache key may be specified in a public property, as seen in the `LaravelWebsite` example class, or if a dynamic key is required, as seen in the `GitHubUsername` example class, a `key` method may be used.

```php
<?php

namespace App\Cache;

use App\Models\User;

class GitHubUsername
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private User $user,
    ) {
        //
    }

    /**
     * Retrieve the cache key.
     *
     * @return string
     */
    public function key()
    {
        return "github-username:{$this->user->id}";
    }

    // ...
}
```

The `key` method is also called by the container allowing method injection.

### TTL

Without a TTL is not specified, the value will be cached forever.

A TTL may be specified via a public `$ttl` property:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache TTL.
     *
     * @var mixed
     */
    public $ttl = 3_600;

    // ...
}
```

Alternatively, a `ttl` method may be used:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * Retrieve the cache TTL.
     *
     * @return mixed
     */
    public function ttl()
    {
        return now()->addHour();
    }

    // ...
}
```

The `ttl` method will be called by the container allowing method injection:

```php
<?php

namespace App\Cache;

use App\Support\Clock;

class LaravelWebsite
{
    /**
     * Retrieve the cache TTL.
     *
     * @return mixed
     */
    public function ttl(Clock $clock)
    {
        return $clock->now()->addHour();
    }

    // ...
}
```

The TTL may also be a `DateTimeInterface` or `DateInterval`, as it already common when using the Cache.

Cache objects also allow durations to be specified as strings, either as a date string:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache TTL.
     *
     * @var mixed
     */
    public $ttl = '1 day';

    // ...
}
```

or as a ISO 8601 duration:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache TTL.
     *
     * @var mixed
     */
    public $ttl = 'P1D';

    // ...
}
```

### Flexible (stale while revalidate)

It is possible to use the `Cache::flexible` feature with cache objects by using a tuple as the TTL:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache TTL.
     *
     * @var mixed
     */
    public $ttl = [3_600, 86_400];

    // ...
}
```

### Hydrating values

Often, you want to store a raw value in the cache but have a rich value returned. The `hydrate` method allows you intercept the value coming from the cache and make alterations to the returned value.

In the following example, the raw HTML will be stored in the cache. When the cache object value is retrieved, a `HtmlString` instance will be returned.

```php
<?php

namespace App\Cache;

use Illuminate\Support\HtmlString;

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

    /**
     * Hydrate the raw cached value.
     *
     * @return mixed
     */
    public function hydrate($html)
    {
        return new HtmlString($html);
    }
}

// --- //

$htmlString = Cache::value(LaravelWebsite::class);

assert($htmlString instanceof HtmlString);
assert($htmlString->toHtml() === Cache::get('laravel-website'));
```

The `hydrate` method is called by the container allowing method injection. The first parameter will always be the cached value:

```php
<?php

namespace App\Cache;

use App\Factories\HtmlStringFactory;

class LaravelWebsite
{
    // ...

    /**
     * Hydrate the raw cached value.
     *
     * @return mixed
     */
    public function hydrate($html, HtmlStringFactory $factory)
    {
        return $factory->make($html);
    }
}
```

### Memoizing values

Similar to the recently introduced `Cache::memo` feature, cache objects can also have their values memoized to  ensure they are only retrieved from the cache once per request, job, or command.

```php
<?php

namespace App\Cache;

use Illuminate\Cache\Attributes\Memoize;

#[Memoize]
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

$html = Cache::value(new LaravelWebsite); // Hits the cache
$html = Cache::value(new LaravelWebsite); // Does not hit the cache
$html = Cache::value(new LaravelWebsite); // Does not hit the cache
```

In the above example, the value returned from the `resolve` method is stored in memory for the lifetime of the request. If the cache object is retrieved again, the in-memory value will be returned rather than hitting the cache.

When the `hydrate` method is present, the value returned from the `hydrate` method will be memoized.

```php
<?php

namespace App\Cache;

use Illuminate\Cache\Attributes\Memoize;

#[Memoize]
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

    public function hydrate($value)
    {
        return new HtmlString($value);
    }
}

$htmlString = Cache::value(new LaravelWebsite); // Hits the cache and creates a new HtmlString instance
$htmlString = Cache::value(new LaravelWebsite); // Does not hit the cache and re-uses the existing HtmlString instance
$htmlString = Cache::value(new LaravelWebsite); // Does not hit the cache and re-uses the existing HtmlString instance
```

Keep in mind that modifications to the memoized object will be persistent across retrievals:

```php
<?php

namespace App\Cache;

use Illuminate\Cache\Attributes\Memoize;

#[Memoize]
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

    public function hydrate($value)
    {
        return new HtmlString($value);
    }
}

$firstHtmlString = Cache::value(new LaravelWebsite);
$firstHtmlString->html = 'foo';

$secondHtmlString = Cache::value(new LaravelWebsite);

assert($firstHtmlString === $secondHtmlString);
assert($secondHtmlString->toHtml() === 'foo');
```

If you intention is to have the cached value memoized and have the hydrate still be called on each retrieval, you may return a `Closure` from the hydrate method:

```php
<?php

namespace App\Cache;

use Illuminate\Cache\Attributes\Memoize;

#[Memoize]
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

    public function hydrate($value)
    {
        return fn () => new HtmlString($value);
    }
}

$firstHtmlString = Cache::value(new LaravelWebsite); // Hits the cache and creates a new HtmlString instance.
$firstHtmlString->html = 'foo';

$secondHtmlString = Cache::value(new LaravelWebsite); // Does not hit the cache but does create a new HtmlString instance.

assert($firstHtmlString !== $secondHtmlString);
assert($secondHtmlString->toHtml() !== 'foo');
```

### Configuring the store

The cache objects own the store they belong to. They use the default store when none is specified.

You may specify a store via the `$store` property:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * The cache store.
     *
     * @var string|null
     */
    public $store = 'redis';

    // ...
}
```

You may also specify the store via a `store` method:

```php
<?php

namespace App\Cache;

class LaravelWebsite
{
    /**
     * Retrieve the cache store.
     *
     * @return string|null
     */
    public function store()
    {
        return 'redis';
    }

    // ...
}
```

The `store` method will be called via the container allowing method injection.

### Accessing the repository

If you would like to have access to the repository class within your cache object, you may implement the `RepositoryAware` contract:

```php
```php
<?php

namespace App\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\RepositoryAware;

class LaravelWebsite implements RepositoryAware
{
    /**
     * The cache store.
     *
     * @var string|null
     */
    public $store = 'redis';

    /**
     * The cache repository.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $repository;

    /**
     * Set the cache repository.
     *
     * @return void
     */
    public function setRepository(Repository $repository)
    {
        $this->repository = $repository;
    }

    // ...
}
```

## Retrieving values

The `Cache::value` method may be used to retrieve a single cache object's value. If the value is not present in the cache, the cache object's `resolve` method will be called and the resulting value will be stored in the cache and returned.

```php
<?php

$html = Cache::value(new LaravelWebsite);

$username = Cache::value(new GitHubUsername($user));
```

If the class does not expect any values in the constructor or if the constructor is expecting services that may be provided by the container, you may pass the class string without having to instantiate the object. The container will make the object under the hood:

```php
<?php

$html = Cache::value(LaravelWebsite::class);
```

If you would like to retrieve multiple values at one time, you may use the `Cache::values` method:

```php
<?php

/** @var list<string> $values */

$values = Cache::values([
    LaravelWebsite::class,
    new GitHubUsername($user),
]);
```

As seen above, each cache object may specify what store it belongs to. This means that you always call `values` directly on the `Cache` facade regardless of their configured store:

```php
<?php

Cache::values([
    LaravelWebsite::class,      // 'redis' store
    new GitHubUsername($user),  // 'file' store
]);
```

## Warming the cache

Sometimes you want to pro-actively warm the cache from a value already easily accessible that might otherwise be more expensive to compute in order to improve an anticipated future request.

Imagine you cache a URL encoded representation of each user's profile image so that it does not need to be downloaded by the user or retrieved from storage.

```php
<?php

namespace App\Cache;

use App\Models\ProfileImage;
use Illuminate\Support\Facades\Storage;

class ProfileImage
{
    public $ttl = '1 day';

    /**
     * Create a new instance.
     */
    public function __construct(
        private $userId,
    ) {
        //
    }

    /**
     * Retrieve the cache key.
     *
     * @return string
     */
    public function key()
    {
        return "profile-image:{$this->userId}";
    }

    public function resolve()
    {
        $profileImage = ProfileImage::firstWhere('user_id', $this->userId);

        $content = Storage::disk('remote')->get($profileImage->path);

        return 'data:image/png;base64,'.base64_encode($content);
    }
}
```

To pro-actively warm this cache, you can use the `Cache::warm` method. It accepts the cache object as the first argument and the second argument is the value to store in the cache. You can see in the following example that it matches what would be returned from the `resolve` method:

```php
<?php

public function update(UpdateProfileImageRequest $request)
{
    $request->user()->profileImage->update([
        'path' => $request->image->store('profile-image'),
    ]);

    $valueToCache = 'data:image/png;base64,'.base64_encode($request->image->getContents());

    Cache::warm(new ProfileImage($request->user()->id, $valueToCache);

    return redirect("/me/");
}
```

The value can be used on subsequent requests without having to be retrieve from storage, i.e., the cache is warm and the `resolve` method is not called:

```blade
<img src="{{ Cache::value(new ProfileImage(Auth::id())) }}" />
```

It is also possible to dehydrate a rich object in the cache object itself. To do this, create a `dehydrate` method. This method will receive the second argument passed to the `Cache::warm` method:

```php
<?php

namespace App\Cache;

use App\Models\ProfileImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileImage
{
    public $ttl = '1 day';

    /**
     * Create a new instance.
     */
    public function __construct(
        private $userId,
    ) {
        //
    }

    /**
     * Retrieve the cache key.
     *
     * @return string
     */
    public function key()
    {
        return "profile-image:{$this->userId}";
    }

    public function resolve()
    {
        $profileImage = ProfileImage::firstWhere('user_id', $this->userId);

        $content = Storage::disk('remote')->get($profileImage->path);

        return 'data:image/png;base64,'.base64_encode($content);
    }

    public function dehydrate(UploadedFile $file)
    {
        return 'data:image/png;base64,'.base64_encode($file->getContents());
    }
}
```

The `Cache::warm` method may now accept the uploaded file,  which will be passed to the `dehydrate` method. The value returned from the `dehydrate` method will be the value stored in the cache:

```php
<?php

public function update(UpdateProfileImageRequest $request)
{
    $request->user()->profileImage->update([
        'path' => $request->image->store('profile-image'),
    ]);

    Cache::warm(new ProfileImage($request->user()->id, $request->image);

    return redirect("/me/");
}
```

The `dehydrate` method may accept multiple different values. For example, it could support both the string and the `UploadedFile`:

```php
<?php

namespace App\Cache;

use App\Models\ProfileImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileImage
{
    // ...

    public function dehydrate(string|UploadedFile $file)
    {
        if (is_string($file)) {
            return $file;
        }

        return 'data:image/png;base64,'.base64_encode($file->getContents());
    }
}
```

Like the `hydrate` method, the `dehydrate` method is called by the container allowing method injection. The first parameter will always be the incoming warm value.

### Warming without a value

It is possible to warm a cache object without having the value to pass into the `Cache::warm` method. Calling `Cache::warm` and passing the cache object will force the `resolve` method to be called and the value in the cache to be refreshed.

```php
<?php

Cache::warm(new LaravelWebsite);
```

You can warm multiple cache objects at one:

```php
<?php

Cache::warm([
    new CachedUser($team->owner->id),
    ...$team->members->map->id->mapInto(CachedUser::class),
]);
```

## Warming via the scheduler

Warming the cache manually works great, however keep the cache warm without having direct interaction is another. This is where the scheduler comes in. Imagine you want to keep the Laravel website cache warm even if you don't have users hitting it right now.

You can configure the schedule to warm objects as needed:

```php
<?php

Schedule::warm([
    LaravelWebsite::class,
])->everyHour();
```

There are other items you might want to refresh slower:

```php
<?php

Schedule::warm([
    LaravelWebsite::class,
    // ...
])->everyDay();

Schedule::warm([
    fn () => User::highTraffic()->get()->mapInto(GithubUsername::class);
    // ...
])->everyFiveMinutes();
```

> [!NOTE] I'm considering wrapping up how often the cache value wants to be warmed into the cache object itself. That means you would not call `everyFiveMinutes` as each object would know when it was last warmed and how often it should be warmed. Not sure on that yet.
