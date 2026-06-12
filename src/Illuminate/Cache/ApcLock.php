<?php

namespace Illuminate\Cache;

class ApcLock extends Lock
{
    /**
     * Create a new lock instance.
     */
    public function __construct(
        protected ApcWrapper $apc,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        return $this->apc->add($this->name, $this->owner, $this->seconds);
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        $this->apc->entry('TODO', function () {
            if ($this->apc->get($this->name) === $this->owner) {
                return $this->apc->delete($this->name);
            }

            return false;
        }, 1); // TTL thoughts? will this cause issues? What happens when we cache null?
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->apc->delete($this->name);
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->apc->get($this->name);
    }
}
