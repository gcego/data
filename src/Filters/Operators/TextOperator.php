<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Support\Like;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Case-insensitive LIKE matching; "%" and "_" in values match literally. Text types only.
 */
abstract readonly class TextOperator extends BuiltInOperator
{
    public function supports(FilterValueType $type): bool
    {
        return $type->textual();
    }

    public function apply(EloquentBuilder|QueryBuilder $query, string $column, mixed $value, FilterValueType $type): void
    {
        Like::where($query, $column, $this->pattern(Like::escape(is_scalar($value) ? (string) $value : '')), $this->negated());
    }

    public function matches(mixed $actual, mixed $value, FilterValueType $type): bool
    {
        $found = Like::matches($actual, is_scalar($value) ? (string) $value : '', $this->position());

        return $this->negated() ? $actual !== null && ! $found : $found;
    }

    /**
     * The LIKE pattern around an escaped value.
     */
    abstract protected function pattern(string $escaped): string;

    /**
     * "any", "start" or "end".
     */
    abstract protected function position(): string;

    protected function negated(): bool
    {
        return false;
    }
}
