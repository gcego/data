<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Times of day, HH:MM or HH:MM:SS; normalized to H:i:s. Ordered: supports ranges.
 */
final readonly class Time extends ValueType
{
    public function name(): string
    {
        return 'time';
    }

    public function describe(): string
    {
        return 'time (HH:MM or HH:MM:SS)';
    }

    public function ordered(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?string
    {
        return match (true) {
            is_string($value) && self::validDate($value, 'H:i:s') => $value,
            is_string($value) && self::validDate($value, 'H:i') => $value.':00',
            default => null,
        };
    }
}
