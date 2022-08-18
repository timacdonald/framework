<?php

namespace Illuminate\Foundation\Routing;

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
     * @return $this
     */
    public function clearOutcomePayload()
    {
        $this->outcomePayload = [];

        return $this;
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
     * @return array
     */
    protected function resolvePrediction()
    {
        $this->clearOutcomePayload();

        ['function' => $function, 'args' => $args] = debug_backtrace(0, 2)[1];

        // TODO: handle the response here.
        $response = $this->{"{$function}Prediction"}(...$args);

        return tap($this->outcomePayload, fn () => $this->clearOutcomePayload());
    }
}
