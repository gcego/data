<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Query\QueryPlan;

/**
 * Providers that compute aggregates where the data lives (the database for
 * builder providers), after every mandatory constraint and request filter.
 */
interface SupportsAggregation
{
    /**
     * @param  list<QueryConstraint>  $constraints
     */
    public function aggregate(SourceDefinition $source, QueryPlan $plan, array $constraints, Aggregation $aggregation): AggregateResult;
}
