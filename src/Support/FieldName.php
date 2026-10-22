<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Rules for developer-declared column and relation paths.
 *
 * Only identifiers are accepted ("name", "topics.name", "subjects.code"), so a
 * trusted field can always be wrapped by the query grammar and can never carry
 * SQL. Request input never supplies column names: it only names allowlisted keys.
 */
final class FieldName
{
    public const string Pattern = '/^[A-Za-z_][A-Za-z0-9_]{0,63}(?:\.[A-Za-z_][A-Za-z0-9_]{0,63}){0,4}$/';

    public static function isValid(string $field): bool
    {
        return preg_match(self::Pattern, $field) === 1;
    }

    /**
     * @throws InvalidSourceException
     */
    public static function assert(string $kind, string $field): string
    {
        if (! self::isValid($field)) {
            throw InvalidSourceException::invalidField($kind, $field);
        }

        return $field;
    }
}
