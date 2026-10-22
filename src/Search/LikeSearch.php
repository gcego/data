<?php

declare(strict_types=1);

namespace Gcego\Data\Search;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\SearchStrategy;
use Gcego\Data\Support\Columns;
use Gcego\Data\Support\Like;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The default search: case-insensitive substring matching with LIKE.
 *
 * Each term must match at least one field. The term is a bound parameter with
 * "%", "_" and the escape character escaped, so wildcards in input match
 * literally. Columns come from the trusted Search definition and are wrapped
 * by the query grammar.
 *
 * Case-insensitivity: PostgreSQL uses ILIKE; MySQL/MariaDB follow the column
 * collation (case-insensitive for the default *_ci collations); SQLite's LIKE
 * is case-insensitive for ASCII. Leading-wildcard LIKE cannot use B-tree
 * indexes: use a SearchStrategy with full-text indexes for large tables.
 */
final class LikeSearch implements SearchStrategy
{
    public const string Escape = Like::Escape;

    public function apply(EloquentBuilder|QueryBuilder $query, array $fields, array $terms, SourceDefinition $source): void
    {
        foreach ($terms as $term) {
            $pattern = '%'.self::escape($term).'%';

            $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($fields, $pattern): void {
                foreach ($fields as $field) {
                    Columns::where($group, $field, static fn (EloquentBuilder|QueryBuilder $target, string $column) => Like::where($target, $column, $pattern), 'or');
                }
            });
        }
    }

    public static function escape(string $term): string
    {
        return Like::escape($term);
    }
}
