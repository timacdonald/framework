<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Route;

class PrecognitiveCallableDispatcher extends CallableDispatcher
{
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

        return $this->container['precognitive.emptyResponse'];
    }
}
