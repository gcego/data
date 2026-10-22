<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;

/**
 * A query-builder source from a trusted factory, called for every execution
 * (joins, unions, reporting views):
 *
 *     BuilderFactory::make(fn () => DB::table('enrolments')->join(...)->select(...))
 *
 * Builder sources are read-only. The closure is application code; it is never
 * serialized, cached or described.
 */
final readonly class BuilderFactory implements SourceConfiguration
{
    private function __construct(public Closure $factory) {}

    public static function make(Closure $factory): self
    {
        return new self($factory);
    }

    /**
     * @return array{builder: string}
     */
    public function normalize(): array
    {
        return ['builder' => 'closure'];
    }
}
