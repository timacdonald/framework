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
     * The incoming and outgoing header.
     *
     * @var string
     */
    protected $header = 'Precognition';

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

        $this->setupPrecognitiveBindings($request);

        return $this->prepareResponse($next($request));
    }

    /**
     * Determine if request is attempting to perceive the future.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isAttemptingPrecognition($request)
    {
        return (bool) $request->header('Precognition');
    }

    /**
     * Setup the bindings for a precognitive request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @retur void
     */
    protected function setupPrecognitiveBindings($request)
    {
        $this->container->instance('precognitive', true);

        $this->container->singleton(
            CallableDispatcher::class,
            fn ($app) => new PrecognitiveCallableDispatcher($app, fn () => $this->finalResponse($request))
        );

        $this->container->singleton(
            ControllerDispatcher::class,
            fn ($app) => new PrecognitiveControllerDispatcher($app, fn () => $this->finalResponse($request))
        );
    }

    /**
     * Create the final response for a precognitive request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function finalResponse($request)
    {
        return $this->container[ResponseFactory::class]->make('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Prepare the outgoing response.
     *
     * @param  \Illuminate\Http\Response  $response
     * @return \Illuminate\Http\Response
     */
    protected function prepareResponse($response)
    {
        return $response->withHeaders([
            'Precognition' => 'true',
            'Vary' => 'Precognition',
        ]);
    }
}
