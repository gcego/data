<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Contracts\CustomOperator;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * @internal Shared rules for built-in and custom operators.
 */
final class Operators
{
    public const string NamePattern = '/^[a-z][a-z0-9_]{0,31}$/';

    public static function name(FilterOperator|CustomOperator $operator): string
    {
        return $operator instanceof FilterOperator ? $operator->value : $operator->name();
    }

    public static function arity(FilterOperator|CustomOperator $operator): int
    {
        return $operator->arity();
    }

    /**
     * @throws InvalidSourceException
     */
    public static function assertCustom(string $node, CustomOperator $operator): CustomOperator
    {
        $name = $operator->name();

        if (preg_match(self::NamePattern, $name) !== 1 || FilterOperator::tryFrom($name) !== null) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('custom operator %s needs a unique lowercase name that is not a built-in operator (got "%s").', $operator::class, mb_substr($name, 0, 40)));
        }

        if (! in_array($operator->arity(), [-1, 0, 1, 2], true)) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('custom operator "%s" must have an arity of -1, 0, 1 or 2.', $name));
        }

        return $operator;
    }
}
