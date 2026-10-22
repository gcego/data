<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

/**
 * A requested sort is not allowed (400).
 */
final class InvalidSortException extends InvalidQueryException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function unknownField(string $field, array $allowed): self
    {
        return self::invalid('sort', sprintf('requests "%s", which is not a sortable field%s', self::safe($field), self::allowed($allowed)));
    }

    public static function tooMany(int $maximum): self
    {
        return self::invalid('sort', sprintf('has more than %d fields.', $maximum));
    }
}
