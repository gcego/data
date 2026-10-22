<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Closure;

/**
 * A trusted, server-side callback stored as a Source property value.
 *
 * Source::normalize() replaces it with a placeholder that carries only this
 * class name, so the closure never reaches caches, snapshots or diagnostics.
 */
final readonly class Callback
{
    public function __construct(public Closure $closure) {}

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->closure)(...$arguments);
    }
}
