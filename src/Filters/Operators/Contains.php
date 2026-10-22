<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Filters\Types\Json;
use Gcego\Data\Support\Compare;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * contains: text contains the value (case-insensitive), or a JSON array
 * contains the element (whereJsonContains).
 */
final readonly class Contains extends TextOperator
{
    public function name(): string
    {
        return 'contains';
    }

    public function supports(FilterValueType $type): bool
    {
        return $type->textual() || $type instanceof Json;
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        $type instanceof Json ? $query->whereJsonContains($column, $value) : parent::apply($query, $column, $value, $type);
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        if ($type instanceof Json) {
            $actual = is_string($actual) ? json_decode($actual, true) : $actual;

            return is_array($actual) && array_filter($actual, static fn (mixed $element): bool => Compare::values($element, $value) === 0) !== [];
        }

        return parent::matches($actual, $value, $type);
    }

    protected function pattern(string $escaped): string
    {
        return '%'.$escaped.'%';
    }

    protected function position(): string
    {
        return 'any';
    }
}
