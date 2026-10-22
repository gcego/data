<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Closure;
use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\Columns;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * doesnt_have: the record has no related record (Eloquent sources; the field is a relation path).
 * Takes no value: filter[topics][doesnt_have]=1. Declared without a value type.
 */
final readonly class DoesntHave extends BuiltInOperator implements RelationOperator
{
    public function name(): string
    {
        return 'doesnt_have';
    }

    public function arity(): int
    {
        return 0;
    }

    public function supports(FilterValueType $type): bool
    {
        return false;
    }

    public function applyRelation(EloquentBuilder $query, string $relation, ?Closure $scope): void
    {
        Columns::whereRelated($query, explode('.', $relation), $scope ?? static function (): void {}, exists: false);
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        throw InvalidSourceException::invalidDefinition('doesnt_have', 'relation operators need an Eloquent source and a relation path.');
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return false;
    }
}
