<?php

namespace Illuminate\Foundation\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\PrecognitiveCallableDispatcher;
use Illuminate\Routing\PrecognitiveControllerDispatcher;

class Precognition
{
    /**
     * The container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->isNotAttemptingPrecognitiveGlance()) {
            return $next($response);
        }

        $this->container->instance(CallableDispatcher::class, fn ($app) => new PrecognitiveCallableDispatcher($app));
        $this->container->instance(ControllerDispatcher::class, fn ($app) => new PrecognitiveControllerDispatcher($app));

        return $next($request)->header('Precognition', '1');
    }
}
