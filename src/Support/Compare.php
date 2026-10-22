<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/**
 * @internal In-memory comparison for collection sources: nulls first, numbers
 * numerically, booleans as booleans, everything else as strings.
 */
final class Compare
{
    public static function values(mixed $a, mixed $b): int
    {
        $a = $a instanceof BackedEnum ? $a->value : $a;
        $b = $b instanceof BackedEnum ? $b->value : $b;

        return match (true) {
            $a === null || $b === null => ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1),
            is_bool($a) || is_bool($b) => (bool) $a <=> (bool) $b,
            is_numeric($a) && is_numeric($b) => (float) $a <=> (float) $b,
            default => strcmp(self::string($a), self::string($b)),
        };
    }

    public static function string(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => '',
        };
    }
}
