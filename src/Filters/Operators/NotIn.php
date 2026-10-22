<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * not_in: none of the values. Like SQL, NULL never matches.
 */
final readonly class NotIn extends BuiltInOperator
{
    public function name(): string
    {
        return 'not_in';
    }

    public function arity(): int
    {
        return -1;
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $query->whereNotIn($column, self::list($value));
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $actual !== null && array_filter(self::list($value), static fn (mixed $candidate): bool => Compare::values($actual, $candidate) === 0) === [];
    }
}
