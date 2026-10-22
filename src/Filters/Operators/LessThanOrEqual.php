<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * lte: less than or equal to the value. Ordered types only (numbers, dates, times).
 */
final readonly class LessThanOrEqual extends BuiltInOperator
{
    public function name(): string
    {
        return 'lte';
    }

    public function supports(FilterValueType $type): bool
    {
        return $type->ordered();
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $query->where($column, '<=', $value);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $actual !== null && Compare::values($actual, $value) <= 0;
    }
}
