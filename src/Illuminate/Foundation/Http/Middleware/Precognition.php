<?php

namespace Illuminate\Foundation\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Foundation\Routing\PrecognitiveCallableDispatcher;
use Illuminate\Foundation\Routing\PrecognitiveControllerDispatcher;

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
        if (! $this->isAttemptingPrecognition($request)) {
            return $next($request);
        }

        $this->prepareForPrecognition($request);

        return $this->handleResponse($next($request));
    }

    /**
     * Determine if request is attempting to perceive the future.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isAttemptingPrecognition($request)
    {
        return in_array($request->header('Precognition'), ['true', '1'], true);
    }

    /**
     * Prepare to handle a precognitive request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @retur void
     */
    protected function prepareForPrecognition($request)
    {
        $this->container->instance('precognitive', true);

        $this->container->singleton(
            CallableDispatcher::class,
            fn ($app) => new PrecognitiveCallableDispatcher($app, fn () => $this->onEmptyResponse($request))
        );

        $this->container->singleton(
            ControllerDispatcher::class,
            fn ($app) => new PrecognitiveControllerDispatcher($app, fn () => $this->onEmptyResponse($request))
        );
    }

    /**
     * The response to return if no other response is provided during precognition.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function onEmptyResponse($request)
    {
        return $this->container[ResponseFactory::class]->make('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Prepare the outgoing precognitive response.
     *
     * @param  \Illuminate\Http\Response  $response
     * @return \Illuminate\Http\Response
     */
    protected function handleResponse($response)
    {
        return $response->withHeaders([
            'Precognition' => 'true',
            'Vary' => 'Precognition',
        ]);
    }
}
