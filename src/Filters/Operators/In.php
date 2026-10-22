<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * in: one of the values (filter[level][]=1&filter[level][]=2 or filter[level][in]=1,2).
 */
final readonly class In extends BuiltInOperator
{
    public function name(): string
    {
        return 'in';
    }

    public function arity(): int
    {
        return -1;
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $query->whereIn($column, self::list($value));
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $actual !== null && array_filter(self::list($value), static fn (mixed $candidate): bool => Compare::values($actual, $candidate) === 0) !== [];
    }
}
