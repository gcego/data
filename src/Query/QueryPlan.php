<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Closure;
use Gcego\Data\Filters\CustomFilter;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Sorting\SortDirection;

/**
 * Validated request input, expressed only in trusted terms (columns from the
 * definition, cast values, capped page sizes). Providers execute plans; they
 * never see raw input.
 */
final readonly class QueryPlan
{
    /**
     * @param  list<Filter>  $filters  Request filters, already resolved to trusted columns and cast values.
     * @param  list<string>  $terms  Search terms.
     * @param  list<array{column: string, direction: SortDirection}>  $sorts  Final ordering, defaults included.
     * @param  array<string, Closure|null>  $includes  Requested, allowlisted relations.
     * @param  array<string, list<string>>  $applied  Request keys that took effect, for metadata: filter, sort, include.
     * @param  list<array{filter: CustomFilter, value: scalar|list<scalar>}>  $customFilters  Requested custom filters with cast values.
     * @param  list<RelationAggregate>  $aggregates  Relationship aggregates to compute (defaults and requested).
     * @param  list<string>|null  $searchFields  Fields this call may search; null means the source's Search fields.
     * @param  int|null  $timeoutMs  Statement timeout for this call.
     * @param  int|null  $maxItems  Most items a collection source may load.
     */
    public function __construct(
        public array $filters = [],
        public array $terms = [],
        public array $sorts = [],
        public array $includes = [],
        public PaginationMode $mode = PaginationMode::Pages,
        public int $perPage = 25,
        public int $page = 1,
        public ?string $cursor = null,
        public array $applied = [],
        public array $customFilters = [],
        public array $aggregates = [],
        public ?array $searchFields = null,
        public ?int $timeoutMs = null,
        public ?int $maxItems = null,
    ) {}

    /**
     * A copy limited to what the caller may read: no filter, sort or search on the given fields.
     *
     * @param  list<string>  $searchFields
     */
    public function withSearchFields(array $searchFields): self
    {
        return new self($this->filters, $this->terms, $this->sorts, $this->includes, $this->mode, $this->perPage, $this->page, $this->cursor, $this->applied, $this->customFilters, $this->aggregates, $searchFields, $this->timeoutMs, $this->maxItems);
    }
}
