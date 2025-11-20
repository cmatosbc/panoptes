<?php

namespace Panoptes\Concurrency;

class RequestHandle
{
    private int $id;
    private bool $done = false;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    // Internal: mark the handle completed
    public function markDone(): void
    {
        $this->done = true;
    }

    public function cancel(): void
    {
        // Cancellation is handled by the Dispatcher; this is a public API placeholder.
    }
}
