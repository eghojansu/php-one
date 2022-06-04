<?php

namespace Ekok\One;

class Event implements EventInterface
{
    private $name;
    private $propagationStopped = false;

    public function __construct(string $name = null)
    {
        $this->name = $name;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function stopPropagation(): static
    {
        $this->propagationStopped = true;

        return $this;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
