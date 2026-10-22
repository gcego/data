<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * between: within two values, inclusive (filter[age][between]=18,65). Ordered types only.
 */
final readonly class Between extends BuiltInOperator
{
    public function name(): string
    {
        return 'between';
    }

    public function arity(): int
    {
        return 2;
    }

    public function supports(FilterValueType $type): bool
    {
        return $type->ordered();
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $query->whereBetween($column, self::list($value));
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        $values = self::list($value);

        return $actual !== null && Compare::values($actual, $values[0] ?? null) >= 0 && Compare::values($actual, $values[1] ?? null) <= 0;
    }
}
