<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * eq: equal to the value (IS NULL for a null fixed-filter value). The default operator.
 */
final readonly class Equal extends BuiltInOperator
{
    public function name(): string
    {
        return 'eq';
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, '=', $value);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $value === null ? $actual === null : $actual !== null && Compare::values($actual, $value) === 0;
    }
}
