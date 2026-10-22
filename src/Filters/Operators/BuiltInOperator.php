<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Gcego\Data\Filters\Types\Json;

/**
 * Shared construction of the built-in operators: Equal::make(), Between::make().
 */
abstract readonly class BuiltInOperator implements Operator
{
    final protected function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function arity(): int
    {
        return 1;
    }

    /**
     * Equality and list operators apply to every type except JSON arrays.
     */
    public function supports(FilterValueType $type): bool
    {
        return ! $type instanceof Json;
    }

    /**
     * @param  scalar|list<scalar>|null  $value
     * @return list<scalar|null>
     */
    protected static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }
}
