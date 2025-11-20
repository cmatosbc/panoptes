<?php

namespace Panoptes\Concurrency;

use Fiber;

class FiberHandle
{
    private Fiber $fiber;
    private bool $started = false;

    public function __construct(Fiber $fiber)
    {
        $this->fiber = $fiber;
    }

    public function getInternalFiber(): Fiber
    {
        return $this->fiber;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function markStarted(): void
    {
        $this->started = true;
    }

    /**
     * Await the fiber result. This will block the caller until the fiber terminates.
     * Intended for use outside the Dispatcher event loop (convenience).
     *
     * @return mixed
     * @throws \Throwable
     */
    public function await()
    {
        // Busy-waiting until the fiber terminates. runBlocking should normally be used.
        while (!$this->fiber->isTerminated()) {
            usleep(10000);
        }

        if ($this->fiber->isTerminated()) {
            return $this->fiber->getReturn();
        }

        return null;
    }

    public function cancel(): void
    {
        // Cancellation is coordinated by the Dispatcher; placeholder here.
    }
}