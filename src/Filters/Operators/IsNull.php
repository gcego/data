<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * null: the field is null (filter[field][null]=1). Takes no value; every type.
 */
final readonly class IsNull extends BuiltInOperator
{
    public function name(): string
    {
        return 'null';
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
        $query->whereNull($column);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return $actual === null;
    }
}
