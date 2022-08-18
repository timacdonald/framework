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
     * Clear the prediction payload.
     *
     * @return void
     */
    public function clearPredictionPayload()
    {
        $this->predictionPayload = [];
    }

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
    protected function resolvePrediction()
    {
        $this->clearPredictionPayload();

        ['function' => $function, 'args' => $args] = debug_backtrace(0, 2)[1];

        // TODO: handle the response here.
        $response = $this->{$function.'Prediction'}(...$args);

        return tap($this->predictionPayload, fn () => $this->clearPredictionPayload());
    }
}
