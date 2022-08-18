<?php

namespace Illuminate\Foundation\Routing;

use Illuminate\Http\JsonResponse;
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
     * @return void
     */
    public function clearOutcomePayload()
    {
        $this->outcomePayload = [];
    }

    /**
     * Retrieve the payload and clear.
     *
     * @return array
     */
    protected function getAndClearPayload()
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
     * @return array
     */
    protected function resolvePrediction()
    {
        $this->clearOutcomePayload();

        ['function' => $function, 'args' => $args] = debug_backtrace(0, 2)[1];

        $response = $this->{"{$function}Prediction"}(...$args);

        if ($response === null) {
            return $this->getAndClearPayload();
        }

        $this->clearOutcomePayload();

        if ($response instanceof Response || $response instanceof JsonResponse) {
            $response->throwResponse();
        }

        throw new RuntimeException('Prediction methods must return null, or an instance of Illuminate\\Http\\Response or Illuminate\\Http\\JsonResponse.');
    }
}
