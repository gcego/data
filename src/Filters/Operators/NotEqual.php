<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * neq: not equal (IS NOT NULL for a null fixed-filter value). Like SQL, NULL never matches.
 */
final readonly class NotEqual extends BuiltInOperator
{
    public function name(): string
    {
        return 'neq';
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $value === null ? $query->whereNotNull($column) : $query->where($column, '!=', $value);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $value === null ? $actual !== null : $actual !== null && Compare::values($actual, $value) !== 0;
    }
}
