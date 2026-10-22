<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Calendar dates, YYYY-MM-DD (a valid date). Ordered: supports ranges.
 */
final readonly class Date extends ValueType
{
    public function name(): string
    {
        return 'date';
    }

    public function describe(): string
    {
        return 'date (YYYY-MM-DD)';
    }

    public function ordered(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?string
    {
        return is_string($value) && self::validDate($value, 'Y-m-d') ? $value : null;
    }
}
