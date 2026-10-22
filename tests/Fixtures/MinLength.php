<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

/**
 * A custom operator an application could add: filter[name][min_length]=6.
 */
final readonly class MinLength implements Operator
{
    public function name(): string
    {
        return 'min_length';
    }

    public function arity(): int
    {
        return 1;
    }

    public function supports(FilterValueType $type): bool
    {
        return $type->textual();
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $query->whereRaw(new Expression('length('.$base->getGrammar()->wrap($column).') >= ?'), [(int) $value]); // @phpstan-ignore argument.type
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        return is_string($actual) && is_numeric($value) && mb_strlen($actual) >= (int) $value;
    }
}
