<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use ReflectionParameter;
use RuntimeException;

class PrecognitiveControllerDispatcher extends ControllerDispatcher
{
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
        $this->ensureMethodExists($controller, $method);

        $arguments = $this->resolveArguments($route, $controller, $method);

        return $this->controllerPrediction($route, $controller, $method, $arguments)
            ?? $this->container['precognitive.emptyResponse'];
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
        $method .= 'Prediction';

        if (! method_exists($controller, $method)) {
            return;
        }

        return tap($controller->{$method}(...array_values($arguments)), function () use ($controller) {
            if (method_exists($controller, 'clearOutcomePayload')) {
                $controller->clearOutcomePayload();
            }
        });
    }

    /**
     * Ensure that the method exists on the controller.
     *
     * @param  object  $controller
     * @param  string  $method
     * @return void
     */
    protected function ensureMethodExists($controller, $method)
    {
        if (method_exists($controller, $method)) {
            return;
        }

        $class = $controller::class;

        throw new RuntimeException("Attempting to predict the outcome of the [{$class}::{$method}()] method but it is not defined.");
    }

    /**
     * Attempt to transform the given parameter into a class instance.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  array  $parameters
     * @param  object  $skippableValue
     * @return mixed
     */
    protected function transformDependency(ReflectionParameter $parameter, $parameters, $skippableValue)
    {
        return tap(parent::transformDependency(...func_get_args()), function ($dependency) {
            if (
                $dependency instanceof FormRequest
                && $dependency->allowsPrecognitionValidationRuleFiltering()
                && $dependency->headers->has('Precognition-Validate-Only')
            ) {
                throw new HttpResponseException($this->container['precognitive.emptyResponse']);
            }
        });
    }
}
