<?php

namespace Ekok\One;

interface EventInterface
{
    public function name(): string|null;
    public function stopPropagation(): static;
    public function isPropagationStopped(): bool;
}
