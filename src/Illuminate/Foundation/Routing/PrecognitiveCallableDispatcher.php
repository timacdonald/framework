<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Route;

class PrecognitiveCallableDispatcher extends CallableDispatcher
{
    /**
     * The empty response resolver.
     *
     * @var callable
     */
    protected $emptyResponseResolver;

    /**
     * Create a new precognitive controller dispatcher instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  callable  $emptyResponseResolver
     */
    public function __construct($container, $emptyResponseResolver)
    {
        parent::__construct($container);

        $this->emptyResponseResolver = $emptyResponseResolver;
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
        $arguments = $this->resolveArguments($route, $callable);

        return ($this->emptyResponseResolver)($route, $callable, $arguments);
    }
}
