<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Query\QueryPlan;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Retrieves records for a source. The only operation every provider must support.
 *
 * Optional capabilities are separate interfaces: ResolvesRecords,
 * SupportsRelationships, SupportsCursorPagination and InspectsQueries.
 *
 * Providers must be stateless between calls (they are shared singletons) and
 * apply, in this order: the mandatory $constraints, the definition's fixed
 * filters, the plan's request filters (in one nested AND group), search (in
 * one nested AND group), ordering, eager loading and pagination.
 */
interface DataProvider
{
    /**
     * Check the definition against what this provider supports. No database access.
     *
     * @throws InvalidSourceException
     */
    public function prepare(SourceDefinition $source): void;

    /**
     * @param  list<QueryConstraint>  $constraints  Trusted constraints: the tenant scope, the source's Constraints queries, registered scopes and context scopes.
     * @return AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>
     *
     * @throws InvalidQueryException
     */
    public function fetch(SourceDefinition $source, QueryPlan $plan, array $constraints): AbstractPaginator|AbstractCursorPaginator;
}
