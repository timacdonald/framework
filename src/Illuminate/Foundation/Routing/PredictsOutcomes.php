<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Http\Response;
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
     * @return $this
     */
    protected function passToOutcome(...$values)
    {
        $this->outcomePayload = array_merge($this->outcomePayload, $values);

        return $this;
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

        $response = $this->{"{$function}Prediction"}(...$args);

        if ($response === null || $response === $this) {
            return tap($this->outcomePayload, fn () => $this->clearOutcomePayload());
        }

        if ($response instanceof Response) {
            $response->throwResponse();
        }

        throw new RuntimeException('Prediction methods must return null or an instance of the Illuminate\\Http\\Response.');
    }
}
