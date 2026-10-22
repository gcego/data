<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A filter operator. Built in: Equal, NotEqual, GreaterThan,
 * GreaterThanOrEqual, LessThan, LessThanOrEqual, Between, In, NotIn,
 * Contains, NotContains, StartsWith, EndsWith, IsNull, IsNotNull, Has and
 * DoesntHave (Gcego\Data\Filters\Operators). Applications implement this
 * contract for their own operators:
 *
 *     Filterable::make('tags', [Json::make(), new JsonLength]);
 *
 * Implementations must be stateless and immutable. The column is always a
 * trusted, qualified column from the source definition; the value has been
 * validated and cast by the filter's FilterValueType and must be bound, never
 * concatenated into SQL.
 */
interface Operator
{
    /**
     * The request name (filter[field][name]): lowercase letters, digits and "_".
     */
    public function name(): string;

    /**
     * Number of values: 1 (scalar), -1 (non-empty list), 2 (pair) or 0 (none).
     */
    public function arity(): int;

    /**
     * Whether the operator is meaningful for the value type.
     */
    public function supports(FilterValueType $type): bool;

    /**
     * Add the condition to a database query.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @param  scalar|list<scalar>|null  $value
     */
    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void;

    /**
     * Whether an in-memory value matches, for collection sources.
     *
     * @param  scalar|list<scalar>|null  $value
     */
    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool;
}
