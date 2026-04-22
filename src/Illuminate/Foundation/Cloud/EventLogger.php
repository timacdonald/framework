<?php

namespace Illuminate\Foundation\Cloud;

class EventLogger
{
    public function emit(array $payload): void
    {
        $this->emitMany([$payload]);
    }

    public function emitMany(array $payloads): void
    {
        //
    }
}
