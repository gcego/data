<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Plain decimal numbers (no exponent). Ordered: supports ranges.
 */
final readonly class Decimal extends ValueType
{
    public function name(): string
    {
        return 'decimal';
    }

    public function describe(): string
    {
        return 'number';
    }

    public function ordered(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && preg_match('/^-?\d{1,15}(?:\.\d{1,10})?$/', $value) === 1 => (float) $value,
            default => null,
        };
    }
}
