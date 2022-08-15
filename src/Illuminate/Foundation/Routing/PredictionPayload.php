<?php

namespace Illuminate\Foundation\Routing;

class PredictionPayload
{
    /**
     * The payload.
     *
     * @var mixed
     */
    protected $payload;

    /**
     * Create a prediction payload.
     *
     * @param  mixed  $payload
     */
    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    /**
     * The underlying payload value.
     *
     * @return mixed
     */
    public function value()
    {
        return $this->payload;
    }
}
