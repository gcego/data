<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

/**
 * Case-insensitive, escaped LIKE conditions shared by search and the text filter operators.
 *
 * The pattern is a bound parameter whose "%", "_" and escape characters were
 * escaped, so input wildcards match literally. PostgreSQL uses ILIKE; MySQL and
 * MariaDB follow the column collation; SQLite's LIKE ignores ASCII case.
 */
final class Like
{
    public const string Escape = '!';

    public static function escape(string $value): string
    {
        return str_replace([self::Escape, '%', '_'], [self::Escape.self::Escape, self::Escape.'%', self::Escape.'_'], $value);
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    public static function where(EloquentBuilder|QueryBuilder $query, string $column, string $pattern, bool $not = false): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $connection = $base->getConnection();
        $operator = $connection instanceof Connection && $connection->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        // whereLike() cannot add an ESCAPE clause, and SQLite has no default escape character.
        // The SQL is not a literal only because the column is: a trusted field that passed
        // FieldName::Pattern and is wrapped by the grammar. The pattern is a binding.
        $sql = sprintf("%s %s%s ? escape '%s'", $base->getGrammar()->wrap($column), $not ? 'not ' : '', $operator, self::Escape);
        $query->whereRaw(new Expression($sql), [$pattern]); // @phpstan-ignore argument.type
    }

    /**
     * In-memory counterpart, for collection sources.
     */
    public static function matches(mixed $actual, string $needle, string $position): bool
    {
        if (! is_scalar($actual) && ! $actual instanceof \Stringable) {
            return false;
        }

        $haystack = mb_strtolower((string) $actual);
        $needle = mb_strtolower($needle);

        return match ($position) {
            'start' => str_starts_with($haystack, $needle),
            'end' => str_ends_with($haystack, $needle),
            default => str_contains($haystack, $needle),
        };
    }
}
