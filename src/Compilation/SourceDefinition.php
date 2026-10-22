<?php

declare(strict_types=1);

namespace Gcego\Data\Compilation;

use Closure;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\RecordTransformer;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\BeforeSave;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Definitions\Limits;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\TenantScoped;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Filters\CustomFilter;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Security\FieldAccess;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Source;
use Illuminate\Database\Eloquent\Model;

/**
 * The compiled, immutable form of a Source: every definition validated and
 * merged into one typed structure the providers execute. Built once per
 * Source instance by SourceCompiler.
 */
final readonly class SourceDefinition
{
    public Pagination $pagination;

    /**
     * @param  class-string<Model>|null  $model
     * @param  list<string>|null  $columns
     * @param  list<QueryConstraint>  $constraints  Trusted Constraints queries, in order.
     * @param  list<Filter>  $filters  Fixed conditions.
     * @param  array<string, Filterable>  $filterables  Request filter allowlist by key.
     * @param  list<array{field: string, direction: SortDirection}>  $sorts  Default ordering.
     * @param  array<string, string>  $sortables  Request sort allowlist: key => column.
     * @param  array<string, Closure|null>  $with  Always eager loaded.
     * @param  array<string, Closure|null>  $includables  Request include allowlist.
     * @param  list<RecordTransformer>  $transforms
     * @param  list<SourceConfiguration>  $extensions  Configuration nodes from other packages.
     * @param  array<string, CustomFilter>  $customFilters  Request filters with custom logic, by key.
     * @param  array<string, RelationAggregate>  $aggregates  Relationship aggregates by alias.
     * @param  array<string, FieldAccess>  $fieldAccess  Field read/write rules by field.
     * @param  list<BeforeSave>  $beforeSave
     */
    public function __construct(
        public string $name,
        public string $provider,
        public Source $source,
        public ?string $model = null,
        public ?string $table = null,
        public ?string $connection = null,
        public ?string $key = null,
        public ?array $columns = null,
        public ?Closure $builder = null,
        public ?Closure $items = null,
        public array $constraints = [],
        public array $filters = [],
        public array $filterables = [],
        public ?Search $search = null,
        public array $sorts = [],
        public array $sortables = [],
        ?Pagination $pagination = null,
        public array $with = [],
        public array $includables = [],
        public array $transforms = [],
        public array $extensions = [],
        public array $customFilters = [],
        public array $aggregates = [],
        public ?TenantScoped $tenant = null,
        public array $fieldAccess = [],
        public ?Writable $writable = null,
        public array $beforeSave = [],
        public ?Cached $cache = null,
        public ?Limits $limits = null,
    ) {
        $this->pagination = $pagination ?? Pagination::make();
    }

    /**
     * Always-computed relationship aggregates.
     *
     * @return list<RelationAggregate>
     */
    public function defaultAggregates(): array
    {
        return array_values(array_filter($this->aggregates, static fn (RelationAggregate $aggregate): bool => ! $aggregate->optional));
    }

    /**
     * Configuration nodes of another package, by class.
     *
     * @template T of SourceConfiguration
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function extensions(string $class): array
    {
        return array_values(array_filter($this->extensions, static fn (SourceConfiguration $node): bool => $node instanceof $class));
    }

    /**
     * Safe, scalar description for diagnostics and tooling. Closures are counted, never described.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'table' => $this->table,
            'connection' => $this->connection,
            'key' => $this->key,
            'columns' => $this->columns,
            'constraints' => count($this->constraints) + ($this->builder !== null ? 1 : 0),
            'filters' => array_map(static fn (Filter $filter): array => ['field' => $filter->field, 'operator' => $filter->operatorName()], $this->filters),
            'filterable' => array_map(static fn (Filterable $filterable): array => $filterable->normalize(), $this->filterables),
            'search' => $this->search?->normalize(),
            'sort' => array_map(static fn (array $sort): array => ['field' => $sort['field'], 'direction' => $sort['direction']->value], $this->sorts),
            'sortable' => $this->sortables,
            'pagination' => $this->pagination->normalize(),
            'with' => array_keys($this->with),
            'includable' => array_keys($this->includables),
            'transforms' => count($this->transforms),
            'custom_filters' => array_map(static fn (CustomFilter $filter): array => $filter->normalize(), $this->customFilters),
            'aggregates' => array_map(static fn (RelationAggregate $aggregate): array => $aggregate->normalize(), $this->aggregates),
            'tenant' => $this->tenant?->column,
            'field_access' => array_map(static fn (FieldAccess $access): array => $access->normalize(), $this->fieldAccess),
            'writable' => $this->writable?->normalize(),
            'before_save' => count($this->beforeSave),
            'cache' => $this->cache?->normalize(),
            'limits' => $this->limits?->normalize(),
            'extensions' => array_map(static fn (SourceConfiguration $node): string => $node::class, $this->extensions),
        ];
    }
}
