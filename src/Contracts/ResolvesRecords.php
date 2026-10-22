<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Relationships\RelationAggregate;

/**
 * Capability: resolve one record by its unique key, inside the same mandatory
 * constraints as fetch(). A record outside them must be reported as missing.
 */
interface ResolvesRecords
{
    /**
     * @param  list<QueryConstraint>  $constraints
     * @param  array<string, Closure|null>  $includes  Allowlisted relations to eager load.
     * @param  list<RelationAggregate>  $aggregates  Relationship aggregates to compute.
     * @return mixed The record, or null when it does not exist within the constraints or the key is malformed.
     */
    public function find(SourceDefinition $source, string|int $key, array $constraints, array $includes = [], array $aggregates = []): mixed;
}
