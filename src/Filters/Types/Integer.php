<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Whole numbers (up to 18 digits). Ordered: supports ranges.
 */
final readonly class Integer extends ValueType
{
    public function name(): string
    {
        return 'integer';
    }

    public function describe(): string
    {
        return 'integer';
    }

    public function ordered(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 => (int) $value,
            default => null,
        };
    }
}
