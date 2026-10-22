<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Relationships\RelationPaths;

/**
 * Eloquent relationships a request may ask to eager load (?include=department,reports):
 *
 *     Includables::make(['department', 'reports', 'reports.department'])
 *
 * Only these exact paths are accepted, at most RelationPaths::MaxDepth deep.
 */
final readonly class Includables implements SourceConfiguration
{
    /**
     * @param  array<string, Closure|null>  $paths
     */
    private function __construct(public array $paths) {}

    /**
     * @param  array<array-key, string|Closure>  $relations
     *
     * @throws InvalidSourceException
     */
    public static function make(array $relations): self
    {
        return new self(RelationPaths::collect('Includables', $relations));
    }

    /**
     * @return array{relations: list<string>}
     */
    public function normalize(): array
    {
        return ['relations' => array_keys($this->paths)];
    }
}
