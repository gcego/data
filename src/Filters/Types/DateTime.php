<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * Date and time, YYYY-MM-DD HH:MM[:SS] or with a "T"; normalized to
 * Y-m-d H:i:s without time-zone conversion. Ordered: supports ranges.
 */
final readonly class DateTime extends ValueType
{
    public function name(): string
    {
        return 'datetime';
    }

    public function describe(): string
    {
        return 'date and time (YYYY-MM-DD HH:MM:SS or ISO 8601 without offset)';
    }

    public function ordered(): bool
    {
        return true;
    }

    protected function convert(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
            if (self::validDate($value, $format)) {
                $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

                return $date === false ? null : $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
