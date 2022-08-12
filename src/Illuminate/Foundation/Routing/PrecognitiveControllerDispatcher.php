<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Container\Container;
use Illuminate\Support\Str;

class PrecognitiveControllerDispatcher extends ControllerDispatcher
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
     * Dispatch a request to a given controller and method.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  mixed  $controller
     * @param  string  $method
     * @return mixed
     */
    public function dispatch(Route $route, $controller, $method)
    {
        $arguments = $this->resolveArguments($route, $controller, $method);

        return $this->controllerPrediction($route, $controller, $method, $arguments)
            ?? ($this->finalResponseResolver)($route, $controller $method, $arguments);
    }

    /**
     * Predict the response from the controller.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  mixed  $controller
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    protected function controllerPrediction($route, $controller, $method, $arguments)
    {
        $predictiveMethod = 'predict'.Str::studly($method);

        if (method_exists($controller, $predictiveMethod)) {
            return $controller->{$predictiveMethod}(...array_values($arguments));
        }
    }
}
