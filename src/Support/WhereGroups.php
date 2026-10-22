<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Illuminate\Database\Query\Builder;

/**
 * Keeps trusted constraints from widening each other.
 *
 * After a constraint runs, the where clauses that existed before it and the
 * ones it added are each wrapped in a nested group when they contain "or",
 * and the groups are joined with AND. A constraint such as
 * fn ($q) => $q->orWhere('is_public', true) therefore yields
 * "(earlier scopes) AND (is_public = 1)", never "earlier OR is_public".
 * Bindings keep their order because the clauses are only regrouped in place.
 */
final class WhereGroups
{
    public static function isolate(Builder $query, int $originalCount): void
    {
        $wheres = $query->wheres;

        if (count($wheres) <= $originalCount) {
            return;
        }

        $query->wheres = [];

        foreach ([array_slice($wheres, 0, $originalCount), array_slice($wheres, $originalCount)] as $slice) {
            $hasOr = array_filter($slice, static fn (mixed $where): bool => is_array($where) && is_string($where['boolean'] ?? null) && str_contains($where['boolean'], 'or')) !== [];

            if (! $hasOr) {
                $query->wheres = [...$query->wheres, ...$slice];

                continue;
            }

            $group = $query->forNestedWhere();
            $group->wheres = $slice;
            $query->wheres[] = ['type' => 'Nested', 'query' => $group, 'boolean' => 'and'];
        }
    }
}
