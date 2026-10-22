<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Query\QueryPlan;

/**
 * Capability: describe the query fetch() would run, without running it.
 */
interface InspectsQueries
{
    /**
     * @param  list<QueryConstraint>  $constraints
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function explain(SourceDefinition $source, QueryPlan $plan, array $constraints): array;
}
