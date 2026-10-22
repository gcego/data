<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * not_null: the field is not null (filter[field][not_null]=1). Takes no value; every type.
 */
final readonly class IsNotNull extends BuiltInOperator
{
    public function name(): string
    {
        return 'not_null';
    }

    public function arity(): int
    {
        return 0;
    }

    public function supports(FilterValueType $type): bool
    {
        return true;
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $query->whereNotNull($column);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $actual !== null;
    }
}
