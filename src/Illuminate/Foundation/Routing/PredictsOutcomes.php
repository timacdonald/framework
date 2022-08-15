<?php

namespace Illuminate\Foundation\Routing;

trait PredictsOutcomes
{
    /**
     * The payload from the prediction that is to be passed to the controller.
     *
     * @var \Illuminate\Foundation\Routing\PredictionPayload|null
     */
    protected $predictionPayload;

    /**
     * Pass data between the prediction method and the non-Precognition method.
     *
     * @return \Illuminate\Foundation\Routing\PredictionPayload
     */
    protected function passToOutcome($payload)
    {
        return tap(new PredictionPayload($payload), fn ($payload) => $this->predictionPayload = $payload);
    }

    /**
     * Run the prediction and return any passed payload.
     *
     * @return mixed
     */
    protected function resolvePrediction($callable = null)
    {
        $this->predictionPayload = null;

        $callable ??= function () {
            $caller = debug_backtrace(0, 3)[2];

            $method = $caller['function'].'Prediction';

            $this->{$method}(...$caller['args']);
        };

        $callable();

        return tap($this->predictionPayload?->value(), fn () => $this->predictionPayload = null);
    }
}
