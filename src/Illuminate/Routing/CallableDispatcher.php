<?php

namespace Illuminate\Routing;

use Illuminate\Container\Container;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use ReflectionFunction;

class CallableDispatcher implements CallableDispatcherContract
{
    use RouteDependencyResolverTrait;

    /**
     * The container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;

    /**
     * Create a new controller dispatcher instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @return void
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Dispatch a request to a given callable.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  callable  $callable
     * @return mixed
     */
    public function dispatch(Route $route, $callable)
    {
        return $callable(...array_values($this->resolveArguments($route, $callable)));
    }

    /**
     * Resolve the arguments for the callable.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  callable  $callable
     * @return array
     */
    protected function resolveArguments(Route $route, $callable)
    {
        return $this->resolveMethodDependencies(
            $route->parametersWithoutNulls(), new ReflectionFunction($callable)
        );
    }
}
