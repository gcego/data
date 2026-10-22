<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A filter operator defined by the application or another package, used like
 * the built-in FilterOperator cases:
 *
 *     new Filterable('tags', [FilterOperator::Equals, new JsonContains])   // filter[tags][json_contains]=php
 *     new Filter('tags', 'php', new JsonContains)
 *
 * Instances must be stateless and immutable. The column is always a trusted,
 * qualified column from the source definition; the value has already been
 * validated and cast by the Filterable's ValueType, and must be bound, never
 * concatenated into SQL.
 */
interface CustomOperator
{
    /**
     * The request name (filter[field][name]): lowercase letters, digits and "_",
     * not one of the built-in FilterOperator names.
     */
    public function name(): string;

    /**
     * Number of values: 1 (scalar), -1 (non-empty list), 2 (pair) or 0 (none).
     */
    public function arity(): int;

    /**
     * Add the condition to a database query.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @param  scalar|list<scalar>|null  $value
     */
    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value): void;

    /**
     * Whether an in-memory item's value matches, for collection sources.
     *
     * @param  scalar|list<scalar>|null  $value
     */
    public function matches(mixed $actual, mixed $value): bool;
}
