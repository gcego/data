<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

/**
 * A requested filter is not allowed or its value is invalid (400).
 */
final class InvalidFilterException extends InvalidQueryException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function unknownField(string $field, array $allowed): self
    {
        return self::invalid('filter['.$field.']', 'is not a filterable field'.self::allowed($allowed));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unsupportedOperator(string $field, string $operator, array $allowed): self
    {
        return self::invalid('filter['.$field.']', sprintf('does not support the "%s" operator. Allowed: %s.', self::safe($operator), implode(', ', $allowed)));
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return self::invalid('filter['.$field.']', $reason);
    }
}
