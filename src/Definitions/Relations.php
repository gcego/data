<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Relationships\RelationPaths;

/**
 * Eloquent relationships that are always eager loaded (one query per relation, never N+1):
 *
 *     Relations::make(['department', 'manager'])
 *     Relations::make(['reports' => fn ($query) => $query->where('is_active', true)])
 *
 * Paths are checked against the model when the source is compiled, and limited
 * to RelationPaths::MaxDepth levels. Constraint closures are trusted code.
 */
final readonly class Relations implements SourceConfiguration
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
        return new self(RelationPaths::collect('Relations', $relations));
    }

    /**
     * @return array{relations: list<string>}
     */
    public function normalize(): array
    {
        return ['relations' => array_keys($this->paths)];
    }
}
