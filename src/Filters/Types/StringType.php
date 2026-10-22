<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Text, up to 255 characters without control characters. Supports equality,
 * lists, null checks and text matching (contains, starts_with, ends_with).
 */
final readonly class StringType extends ValueType
{
    public function name(): string
    {
        return 'string';
    }

    public function describe(): string
    {
        return 'string';
    }

    public function textual(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
