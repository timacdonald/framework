<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use RuntimeException;

trait PredictsOutcomes
{
    /**
     * The payload from the prediction to be resolved in the outcome.
     *
     * @var array
     */
    protected $outcomePayload = [];

    /**
     * Clear the outcome payload.
     *
     * @return void
     */
    public function clearOutcomePayload()
    {
        $this->outcomePayload = [];
    }

    /**
     * Retrieve and clear the payload.
     *
     * @return array
     */
    protected function pullOutcomePayload()
    {
        return tap($this->outcomePayload, fn () => $this->clearOutcomePayload());
    }

    /**
     * Pass data to the outcome function.
     *
     * @param  ...mixed  $values
     * @return void
     */
    protected function passToOutcome(...$values)
    {
        $this->outcomePayload = array_merge($this->outcomePayload, $values);
    }

    /**
     * Run the prediction and return any passed payload.
     *
     * @param  null|\Illuminate\Http\Request  $request
     * @return array
     */
    protected function resolvePrediction($request = null)
    {
        $this->clearOutcomePayload();

        ['function' => $function, 'args' => $args] = debug_backtrace(0, 2)[1];

        $response = $this->{"{$function}Prediction"}(...$args);

        if ($response === null) {
            return $this->pullOutcomePayload();
        }

        $this->clearOutcomePayload();

        throw new HttpResponseException(
            Router::toResponse($request ?? app('request'), $response)
        );
    }
}
