<?php

namespace Illuminate\Cache;

use RuntimeException;

class Ttl
{
    public function __construct(
        protected ?int $milliseconds
    ) {
        //
    }

    public static function forever()
    {
        return new self(null);
    }

    public function fromMilliseconds(int $milliseconds)
    {
        return new self($milliseconds);
    }

    public static function fromSeconds(int $seconds)
    {
        if ($seconds === 0) {
            throw new RuntimeException('TTL seconds cannot be zero.');
        }

        return new self($seconds * 1000);
    }

    public function isForever(): bool
    {
        return $this->milliseconds === null;
    }

    public function inSeconds(): int
    {
        if ($this->milliseconds === null) {
            throw new RuntimeException('TTL is forever. Unable to retrieve duration.');
        }

        return (int) ($this->milliseconds / 1000);
    }
}
