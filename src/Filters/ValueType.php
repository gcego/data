<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use DateTimeImmutable;
use Gcego\Data\Exceptions\InvalidFilterException;

/**
 * The type of a filterable field. Request values are validated and cast to it
 * before they reach a query, where they are always bound as parameters.
 */
enum ValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';

    public const int MaxStringLength = 255;

    /**
     * @throws InvalidFilterException
     */
    public function cast(string $field, mixed $value): string|int|float|bool
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $result = match ($this) {
            self::String => is_string($value) || is_int($value) || is_float($value) ? (string) $value : null,
            self::Integer => self::integer($value),
            self::Decimal => is_int($value) || is_float($value) ? (float) $value : (is_string($value) && preg_match('/^-?\d{1,15}(?:\.\d{1,10})?$/', $value) === 1 ? (float) $value : null),
            self::Boolean => self::boolean($value),
            self::Date => is_string($value) && self::date($value, 'Y-m-d') ? $value : null,
            self::DateTime => is_string($value) ? self::dateTime($value) : null,
        };

        if ($result === null) {
            throw InvalidFilterException::invalidValue($field, sprintf('must be a valid %s.', $this->describe()));
        }

        if (is_string($result) && (mb_strlen($result) > self::MaxStringLength || preg_match('/[\x00-\x1F\x7F]/', $result) === 1)) {
            throw InvalidFilterException::invalidValue($field, sprintf('must be at most %d characters without control characters.', self::MaxStringLength));
        }

        return $result;
    }

    public function describe(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Integer => 'integer',
            self::Decimal => 'number',
            self::Boolean => 'boolean (true, false, 1 or 0)',
            self::Date => 'date (YYYY-MM-DD)',
            self::DateTime => 'date and time (YYYY-MM-DD HH:MM:SS or ISO 8601 without offset)',
        };
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    private static function date(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    private static function dateTime(string $value): ?string
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
            if (self::date($value, $format)) {
                return (DateTimeImmutable::createFromFormat('!'.$format, $value) ?: throw new \LogicException)->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
