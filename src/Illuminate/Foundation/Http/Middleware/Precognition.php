<?php

namespace Illuminate\Foundation\Http\Middleware;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Routing\PrecognitiveCallableDispatcher;
use Illuminate\Foundation\Routing\PrecognitiveControllerDispatcher;
use Illuminate\Http\Response;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Support\Collection;

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
     * @return \Illuminate\Http\Response
     */
    public function handle($request, $next)
    {
        if (! $this->isAttemptingPrecognition($request)) {
            return $this->appendVaryHeader($request, $next($request));
        }

        $this->prepareForPrecognition($request);

        return $this->withPrecognitiveResponse($request, $next($request));
    }

    /**
     * Determine if request is attempting to perceive the future.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isAttemptingPrecognition($request)
    {
        return $request->header('Precognition') === 'true';
    }

    /**
     * Prepare to handle a precognitive request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @retur void
     */
    protected function prepareForPrecognition($request)
    {
        // if we create an inherited skeleton version of this middleware the
        // method should be defined, call the parent version, and have this as
        // a serving suggestion, maybe. Otherwise a good snippet for the docs.
        //
        // if ($request->is('admin/*')) {
        //     $request->withPrecognitiveClientRuleFiltering();
        // }

        $request->attributes->set('precognitive', true);

        $this->container->bind('precognitive.ruleResolver', fn () => fn ($rules, $r = null) => $this->filterValidationRules($r ?? $request, $rules));
        $this->container->bind('precognitive.emptyResponse', fn () => $this->onEmptyResponse($request));
        $this->container->bind(CallableDispatcher::class, fn ($app) => new PrecognitiveCallableDispatcher($app));
        $this->container->bind(ControllerDispatcher::class, fn ($app) => new PrecognitiveControllerDispatcher($app));
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
     * Resolve the validation rules for a Precognitive request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $rules
     * @return array
     */
    protected function filterValidationRules($request, $rules)
    {
        if (! $request->precognitiveClientRuleFiltering() || ! $request->headers->has('Precognition-Validate-Only')) {
            return $rules;
        }

        return Collection::make($rules)
            ->only(explode(',', $request->header('Precognition-Validate-Only')))
            ->all();
    }

    /**
     * Customize the response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return \Illuminate\Http\Response
     */
    protected function withPrecognitiveResponse($request, $response)
    {
        return $this->appendVaryHeader($request, $response->header('Precognition', 'true'));
    }

    /**
     * Append the "Vary" header to the response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return \Illuminate\Http\Response
     */
    protected function appendVaryHeader($request, $response)
    {
        return $response->header('Vary', implode(', ', array_filter([
            $response->headers->get('Vary'),
            'Precognition',
        ])));
    }
}
