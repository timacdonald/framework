<?php

namespace Illuminate\Foundation\Routing;

trait PredictsOutcomes
{
    /**
     * The payload from the prediction to be resolved in the controller.
     *
     * @var array
     */
    protected $predictionPayload = [];

    /**
     * Pass data between the prediction method and the non-Precognition method.
     *
     * @param  ...mixed  $payload
     * @return void
     */
    protected function passToOutcome(...$payload)
    {
        $this->predictionPayload = array_merge($this->predictionPayload, $args);
    }

    /**
     * Run the prediction and return any passed payload.
     *
     * @return array
     */
    protected function resolvePrediction($callable = null)
    {
        $this->predictionPayload = [];

        $callable ??= function () {
            $caller = debug_backtrace(0, 3)[2];

            $method = $caller['function'].'Prediction';

            $response = $this->{$method}(...$caller['args']);
        };

        $callable();

        return tap($this->predictionPayload, fn () => $this->predictionPayload = []);
    }
}
