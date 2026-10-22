<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Applies a condition to a trusted field.
 *
 * Eloquent builders: "name" is qualified with the model table; a dotted path
 * ("topics.name", "topics.lessons.title") means "has a related record whose
 * column matches", through whereHas, so relation scopes apply.
 * Query builders: the field is used as written ("name" or "subjects.name").
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
            $relation = substr($field, 0, (int) strrpos($field, '.'));
            $column = substr($field, (int) strrpos($field, '.') + 1);
            $constraint = static function (EloquentBuilder $related) use ($condition, $column): void {
                $condition($related, $related->qualifyColumn($column));
            };

            $boolean === 'or' ? $query->orWhereHas($relation, $constraint) : $query->whereHas($relation, $constraint);

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
}
