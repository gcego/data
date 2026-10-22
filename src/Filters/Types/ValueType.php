<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Exceptions\InvalidFilterException;

/**
 * Shared behaviour of the built-in value types: string length and control
 * character limits, and one consistent error for invalid values.
 */
abstract readonly class ValueType implements FilterValueType
{
    public const int MaxStringLength = 255;

    final protected function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function cast(string $field, mixed $value): string|int|float|bool
    {
        $result = $this->convert($value instanceof \BackedEnum ? $value->value : $value);

        if ($result === null) {
            throw InvalidFilterException::invalidValue($field, sprintf('must be a valid %s.', $this->describe()));
        }

        if (is_string($result) && (mb_strlen($result) > self::MaxStringLength || preg_match('/[\x00-\x1F\x7F]/', $result) === 1)) {
            throw InvalidFilterException::invalidValue($field, sprintf('must be at most %d characters without control characters.', self::MaxStringLength));
        }

        return $result;
    }

    public function ordered(): bool
    {
        return false;
    }

    public function textual(): bool
    {
        return false;
    }

    public function options(): array
    {
        return [];
    }

    /**
     * The cast value, or null when the value is invalid.
     */
    abstract protected function convert(mixed $value): string|int|float|bool|null;

    protected static function validDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $date !== false && $date->format($format) === $value;
    }
}
