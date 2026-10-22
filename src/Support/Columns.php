<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Closure;
use Gcego\Data\Tenancy\RelationScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Applies a condition to a trusted field.
 *
 * Eloquent builders: "name" is qualified with the model table; a dotted path
 * ("department.name", "reports.department.name") means "has a related record
 * whose column matches", one whereHas per relation, each limited to the
 * call's tenant (RelationScope), so relation scopes and tenancy apply.
 * Query builders: the field is used as written ("name" or "employees.name").
 */
final class Columns
{
    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @param  Closure(EloquentBuilder<Model>|QueryBuilder, string): void  $condition  Receives the builder and the qualified column.
     * @param  'and'|'or'  $boolean
     */
    public static function where(EloquentBuilder|QueryBuilder $query, string $field, Closure $condition, string $boolean = 'and'): void
    {
        if ($query instanceof EloquentBuilder && str_contains($field, '.')) {
            $segments = explode('.', $field);
            $column = (string) array_pop($segments);
            self::whereRelated($query, $segments, static function (EloquentBuilder $related) use ($condition, $column): void {
                $condition($related, $related->qualifyColumn($column));
            }, $boolean);

            return;
        }

        $column = $query instanceof EloquentBuilder ? $query->qualifyColumn($field) : $field;

        if ($boolean === 'or') {
            $query->orWhere(static function (EloquentBuilder|QueryBuilder $group) use ($condition, $column): void {
                $condition($group, $column);
            });

            return;
        }

        $condition($query, $column);
    }

    /**
     * whereHas through every relation of the path, tenant-scoped at each level.
     *
     * @param  EloquentBuilder<Model>  $query
     * @param  list<string>  $relations
     * @param  Closure(EloquentBuilder<Model>): void  $condition  Applied to the last related query.
     * @param  'and'|'or'  $boolean
     */
    public static function whereRelated(EloquentBuilder $query, array $relations, Closure $condition, string $boolean = 'and', bool $exists = true): void
    {
        $relation = array_shift($relations);
        $nested = static function (EloquentBuilder $related) use ($relations, $condition): void {
            RelationScope::apply($related);
            $relations === [] ? $condition($related) : self::whereRelated($related, $relations, $condition);
        };

        match (true) {
            ! $exists => $boolean === 'or' ? $query->orWhereDoesntHave((string) $relation, $nested) : $query->whereDoesntHave((string) $relation, $nested),
            $boolean === 'or' => $query->orWhereHas((string) $relation, $nested),
            default => $query->whereHas((string) $relation, $nested),
        };
    }
}
