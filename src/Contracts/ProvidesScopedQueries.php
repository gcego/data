<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Query\QueryPlan;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Providers that hand out the rows a request may see as an unordered
 * builder (every mandatory constraint, fixed filter, request filter and
 * search applied), for trusted packages that aggregate on the database
 * themselves (gcego/analytics). Callers may narrow the builder; they must
 * never remove its conditions.
 */
interface ProvidesScopedQueries
{
    /**
     * @param  list<QueryConstraint>  $constraints
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    public function scoped(SourceDefinition $source, QueryPlan $plan, array $constraints): EloquentBuilder|QueryBuilder;
}
