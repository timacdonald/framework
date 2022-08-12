<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Routing\CallableDispatcher;

class PrecognitiveCallableDispatcher extends CallableDispatcher
{
    /**
     * The final response resolver.
     *
     * @var callable
     */
    protected $finalResponseResolver;

    /**
     * Create a new precognitive controller dispatcher instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  callable  $finalResponseResolver
     */
    public function __construct(Container $container, $finalResponseResolver)
    {
        parent::__construct($container);

        $this->finalResponseResolver = $finalResponseResolver;
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
        $this->resolveArguments($route, $callable);

        return ($this->finalResponseResolver)($route, $callable);
    }
}
