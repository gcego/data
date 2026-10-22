<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Closure;
use Gcego\Data\Aggregation\AggregateFunction;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Aggregation\TimeBucket;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\InspectsQueries;
use Gcego\Data\Contracts\ProvidesScopedQueries;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Contracts\SearchStrategy;
use Gcego\Data\Contracts\SupportsAggregation;
use Gcego\Data\Contracts\SupportsCursorPagination;
use Gcego\Data\Contracts\SupportsMutations;
use Gcego\Data\DataConfig;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Performance\StatementTimeout;
use Gcego\Data\Query\QueryPlan;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Search\LikeSearch;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Support\Columns;
use Gcego\Data\Support\WhereGroups;
use Gcego\Data\Tenancy\RelationScope;
use Gcego\Data\Tenancy\TenantConstraint;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use UnexpectedValueException;
use WeakMap;

/**
 * Shared pipeline of the Eloquent and query-builder providers. Steps, in order:
 *
 *  1. base query (Eloquent: with global scopes and soft deletes)
 *  2. trusted constraints, each isolated in its own where group (WhereGroups)
 *  3. fixed Filter conditions (AND)
 *  4. request filters and custom filters, in one nested AND group
 *  5. search, one nested AND group per term
 *  6. ordering, with the unique key appended for stable pages
 *  7. eager loading and relationship aggregates (Eloquent only)
 *  8. pagination: paginate(), simplePaginate() or cursorPaginate(),
 *     under the plan's statement timeout
 *
 * Every value is bound; every column comes from the definition.
 */
abstract class BuilderProvider implements DataProvider, InspectsQueries, ProvidesScopedQueries, ResolvesRecords, SupportsAggregation, SupportsCursorPagination, SupportsMutations
{
    /**
     * @var WeakMap<SourceDefinition, true>
     */
    private WeakMap $prepared;

    public function __construct(
        protected readonly DataConfig $config,
        protected readonly Container $container,
    ) {
        $this->prepared = new WeakMap;
    }

    /**
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    abstract protected function newQuery(SourceDefinition $source): EloquentBuilder|QueryBuilder;

    /**
     * The unique key column, qualified as it must appear in the query.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    abstract protected function keyColumn(EloquentBuilder|QueryBuilder $query, SourceDefinition $source): string;

    /**
     * The key normalized for the key column, or null when it cannot identify a record.
     */
    abstract protected function normalizeKey(SourceDefinition $source, string|int $key): string|int|null;

    /**
     * Provider-specific checks, run once per definition.
     *
     * @throws InvalidSourceException
     */
    abstract protected function validate(SourceDefinition $source): void;

    public function prepare(SourceDefinition $source): void
    {
        if (isset($this->prepared[$source])) {
            return;
        }

        $this->validate($source);
        $this->prepared[$source] = true;
    }

    public function fetch(SourceDefinition $source, QueryPlan $plan, array $constraints): AbstractPaginator|AbstractCursorPaginator
    {
        return RelationScope::run(self::tenantOf($constraints), fn (): AbstractPaginator|AbstractCursorPaginator => $this->paginate($source, $plan, $constraints));
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     * @return AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>
     */
    private function paginate(SourceDefinition $source, QueryPlan $plan, array $constraints): AbstractPaginator|AbstractCursorPaginator
    {
        $query = $this->build($source, $plan, $constraints);
        $columns = $this->columns($source);

        try {
            $paginator = StatementTimeout::run(self::connectionOf($query), $plan->timeoutMs, $source->name, static fn () => match ($plan->mode) {
                PaginationMode::Pages => $query->paginate($plan->perPage, $columns, 'page', $plan->page),
                PaginationMode::Simple => $query->simplePaginate($plan->perPage, $columns, 'page', $plan->page),
                PaginationMode::Cursor => $query->cursorPaginate($plan->perPage, $columns, 'cursor', $plan->cursor),
            });
        } catch (UnexpectedValueException) {
            throw InvalidQueryException::invalid('cursor', 'does not match the current ordering; start again from the first page.');
        }

        /** @var AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed> $paginator */
        return $paginator->through(fn (mixed $row): mixed => $this->item($row));
    }

    public function find(SourceDefinition $source, string|int $key, array $constraints, array $includes = [], array $aggregates = []): mixed
    {
        return RelationScope::run(self::tenantOf($constraints), fn (): mixed => $this->findRecord($source, $key, $constraints, $includes, $aggregates));
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     * @param  array<string, Closure|null>  $includes
     * @param  list<RelationAggregate>  $aggregates
     */
    private function findRecord(SourceDefinition $source, string|int $key, array $constraints, array $includes, array $aggregates): mixed
    {
        $key = $this->normalizeKey($source, $key);

        if ($key === null) {
            return null;
        }

        $query = $this->constrained($source, $constraints);
        $this->load($query, [...$source->with, ...$includes]);
        $this->loadAggregates($query, $aggregates);
        $row = $query->where($this->keyColumn($query, $source), '=', $key)->first($this->columns($source));

        return $row === null ? null : $this->item($row);
    }

    public function lock(SourceDefinition $source, string|int $key, array $constraints, bool $trashed = false): mixed
    {
        return RelationScope::run(self::tenantOf($constraints), fn (): mixed => $this->lockRecord($source, $key, $constraints, $trashed));
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     */
    private function lockRecord(SourceDefinition $source, string|int $key, array $constraints, bool $trashed): mixed
    {
        $key = $this->normalizeKey($source, $key);

        if ($key === null) {
            return null;
        }

        $query = $this->constrained($source, $constraints, $trashed);
        $row = $query->where($this->keyColumn($query, $source), '=', $key)->lockForUpdate()->first($this->columns($source));

        return $row === null ? null : $this->item($row);
    }

    public function explain(SourceDefinition $source, QueryPlan $plan, array $constraints): array
    {
        $query = RelationScope::run(self::tenantOf($constraints), fn (): EloquentBuilder|QueryBuilder => $this->build($source, $plan, $constraints));
        $query = $plan->mode === PaginationMode::Cursor ? $query->limit($plan->perPage + 1) : $query->forPage($plan->page, $plan->perPage);

        return ['sql' => $query->toSql(), 'bindings' => array_values($query->getBindings())];
    }

    /**
     * @param  array<string, Closure|null>  $relations
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    protected function load(EloquentBuilder|QueryBuilder $query, array $relations): void {}

    /**
     * @param  list<RelationAggregate>  $aggregates
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    protected function loadAggregates(EloquentBuilder|QueryBuilder $query, array $aggregates): void {}

    /**
     * Limit a query to soft-deleted records (for restore).
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    protected function onlyTrashed(EloquentBuilder|QueryBuilder $query, SourceDefinition $source): void
    {
        throw InvalidSourceException::invalid($source->name, 'only Eloquent models with SoftDeletes can be restored.');
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    protected static function connectionOf(EloquentBuilder|QueryBuilder $query): ConnectionInterface
    {
        return ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->getConnection();
    }

    /**
     * @return list<string>
     */
    protected function columns(SourceDefinition $source): array
    {
        return $source->columns ?? ['*'];
    }

    protected function item(mixed $row): mixed
    {
        return $row;
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    protected function build(SourceDefinition $source, QueryPlan $plan, array $constraints): EloquentBuilder|QueryBuilder
    {
        $query = $this->filtered($source, $plan, $constraints);

        $this->loadAggregates($query, $plan->aggregates);
        $this->order($query, $source, $plan);
        $this->load($query, [...$source->with, ...$plan->includes]);

        return $query;
    }

    /**
     * Aggregates in the database, after every mandatory constraint, filter and search term.
     *
     * @param  list<QueryConstraint>  $constraints
     */
    public function aggregate(SourceDefinition $source, QueryPlan $plan, array $constraints, Aggregation $aggregation): AggregateResult
    {
        return RelationScope::run(self::tenantOf($constraints), fn (): AggregateResult => $this->aggregateRows($source, $plan, $constraints, $aggregation));
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     */
    private function aggregateRows(SourceDefinition $source, QueryPlan $plan, array $constraints, Aggregation $aggregation): AggregateResult
    {
        $query = $this->filtered($source, $plan, $constraints);
        $qualify = static fn (string $column): string => $query instanceof EloquentBuilder ? $query->qualifyColumn($column) : $column;

        if ($aggregation->dateColumn !== null && $aggregation->from !== null) {
            $query->where($qualify($aggregation->dateColumn), '>=', $aggregation->from->format('Y-m-d H:i:s'));
        }

        if ($aggregation->dateColumn !== null && $aggregation->to !== null) {
            $query->where($qualify($aggregation->dateColumn), '<=', $aggregation->to->format('Y-m-d H:i:s'));
        }

        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $column = $aggregation->column === null ? null : $qualify($aggregation->column);

        if (! $aggregation->isGrouped()) {
            $value = StatementTimeout::run($base->getConnection(), $plan->timeoutMs, $source->name, static fn (): mixed => match ($aggregation->function) {
                AggregateFunction::Count => $column === null ? $base->count() : $base->count($column),
                AggregateFunction::Sum => $base->sum((string) $column),
                AggregateFunction::Average => $base->avg((string) $column),
                AggregateFunction::Minimum => $base->min((string) $column),
                AggregateFunction::Maximum => $base->max((string) $column),
            });

            return new AggregateResult(self::number($value));
        }

        $grammar = $base->getGrammar();
        $key = $aggregation->groupBy !== null
            ? $grammar->wrap($qualify($aggregation->groupBy))
            : self::bucket($base, $grammar->wrap($qualify((string) $aggregation->dateColumn)), $aggregation->bucket ?? TimeBucket::Day);
        $value = sprintf('%s(%s)', strtoupper($aggregation->function->value), $column === null ? '*' : $grammar->wrap($column));

        // Not literal SQL only because of the identifiers: trusted Aggregation columns
        // (FieldName rules, no relation paths) wrapped by the grammar, and fixed bucket
        // formats per driver. Every value (dates, filters) is a binding.
        $grouped = $base->selectRaw($key.' as gcego_key, '.$value.' as gcego_value') // @phpstan-ignore argument.type
            ->groupByRaw($key) // @phpstan-ignore argument.type
            ->orderByRaw($key) // @phpstan-ignore argument.type
            ->limit($aggregation->limit + 1);
        $rows = StatementTimeout::run($base->getConnection(), $plan->timeoutMs, $source->name, static fn (): Collection => $grouped->get());

        $groups = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $groups[] = ['key' => is_scalar($data['gcego_key'] ?? null) ? (string) $data['gcego_key'] : '', 'value' => self::number($data['gcego_value'] ?? null) ?? 0];
        }

        return new AggregateResult(null, array_slice($groups, 0, $aggregation->limit), count($groups) > $aggregation->limit);
    }

    public function scoped(SourceDefinition $source, QueryPlan $plan, array $constraints): EloquentBuilder|QueryBuilder
    {
        return RelationScope::run(self::tenantOf($constraints), fn (): EloquentBuilder|QueryBuilder => $this->filtered($source, $plan, $constraints));
    }

    /**
     * The tenant of a call, from its tenant constraint.
     *
     * @param  list<QueryConstraint>  $constraints
     */
    protected static function tenantOf(array $constraints): string|int|null
    {
        foreach ($constraints as $constraint) {
            if ($constraint instanceof TenantConstraint) {
                return $constraint->tenant;
            }
        }

        return null;
    }

    /**
     * Steps 1-3 plus request filters and search: the rows a request may see, unordered.
     *
     * @param  list<QueryConstraint>  $constraints
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    protected function filtered(SourceDefinition $source, QueryPlan $plan, array $constraints): EloquentBuilder|QueryBuilder
    {
        $query = $this->constrained($source, $constraints);

        if ($plan->filters !== [] || $plan->customFilters !== []) {
            $query->where(function (EloquentBuilder|QueryBuilder $group) use ($plan, $source): void {
                foreach ($plan->filters as $filter) {
                    $this->condition($group, $filter);
                }

                foreach ($plan->customFilters as ['filter' => $filter, 'value' => $value]) {
                    $result = $filter->apply($group, $value, $source);

                    if ($result !== null && $result !== $group) {
                        throw InvalidSourceException::invalid($source->name, sprintf('custom filter "%s" must modify and return the builder it receives.', $filter->key));
                    }
                }
            });
        }

        $fields = $plan->searchFields ?? $source->search->fields ?? [];

        if ($plan->terms !== [] && $source->search !== null && $fields !== []) {
            $class = $source->search->strategy;
            $strategy = $class === null ? new LikeSearch : $this->container->make($class);

            if (! $strategy instanceof SearchStrategy) {
                throw InvalidSourceException::invalid($source->name, sprintf('%s is not a search strategy.', $class));
            }

            $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($strategy, $source, $plan, $fields): void {
                $strategy->apply($group, $fields, $plan->terms, $source);
            });
        }

        return $query;
    }

    /**
     * A database expression grouping a wrapped date column into buckets.
     */
    private static function bucket(QueryBuilder $query, string $column, TimeBucket $bucket): string
    {
        $connection = $query->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : 'sqlite';

        return match ($driver) {
            'mysql', 'mariadb' => sprintf("DATE_FORMAT(%s, '%s')", $column, match ($bucket) {
                TimeBucket::Day => '%Y-%m-%d',
                TimeBucket::Week => '%x-W%v',
                TimeBucket::Month => '%Y-%m',
                TimeBucket::Year => '%Y',
            }),
            'pgsql' => sprintf("to_char(%s, '%s')", $column, match ($bucket) {
                TimeBucket::Day => 'YYYY-MM-DD',
                TimeBucket::Week => 'IYYY-"W"IW',
                TimeBucket::Month => 'YYYY-MM',
                TimeBucket::Year => 'YYYY',
            }),
            'sqlsrv' => match ($bucket) {
                TimeBucket::Week => sprintf("CONCAT(YEAR(DATEADD(day, 26 - DATEPART(iso_week, %1\$s), %1\$s)), '-W', RIGHT('0' + CAST(DATEPART(iso_week, %1\$s) AS varchar(2)), 2))", $column),
                default => sprintf("FORMAT(%s, '%s')", $column, match ($bucket) {
                    TimeBucket::Day => 'yyyy-MM-dd',
                    TimeBucket::Month => 'yyyy-MM',
                    default => 'yyyy',
                }),
            },
            default => sprintf("strftime('%s', %s)", match ($bucket) {
                TimeBucket::Day => '%Y-%m-%d',
                TimeBucket::Week => '%Y-W%W',
                TimeBucket::Month => '%Y-%m',
                TimeBucket::Year => '%Y',
            }, $column),
        };
    }

    private static function number(mixed $value): int|float|null
    {
        return match (true) {
            is_int($value), is_float($value) => $value,
            is_string($value) && is_numeric($value) => str_contains($value, '.') || str_contains(strtolower($value), 'e') ? (float) $value : (int) $value,
            default => null,
        };
    }

    /**
     * Steps 1-3: the base query with every mandatory condition.
     *
     * @param  list<QueryConstraint>  $constraints
     * @param  bool  $trashed  Only soft-deleted records (restore).
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    protected function constrained(SourceDefinition $source, array $constraints, bool $trashed = false): EloquentBuilder|QueryBuilder
    {
        $query = $this->newQuery($source);

        if ($trashed) {
            $this->onlyTrashed($query, $source);
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        foreach ($constraints as $constraint) {
            $before = count($base->wheres);
            $result = $constraint->apply($query, $source);

            if ($result !== null && $result !== $query) {
                throw InvalidSourceException::invalid($source->name, sprintf('constraint %s must modify and return the builder it receives, not %s.', $constraint::class, $result instanceof Collection ? 'a collection' : 'a different builder'));
            }

            WhereGroups::isolate($base, $before);
        }

        foreach ($source->filters as $filter) {
            $this->condition($query, $filter);
        }

        return $query;
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    protected function condition(EloquentBuilder|QueryBuilder $query, Filter $filter): void
    {
        $operator = $filter->operator;

        if ($operator instanceof RelationOperator) {
            if (! $query instanceof EloquentBuilder) {
                throw InvalidSourceException::invalidDefinition('Filter "'.$filter->field.'"', 'has and doesnt_have need an Eloquent model source.');
            }

            $operator->applyRelation($query, $filter->field, null);

            return;
        }

        Columns::where($query, $filter->field, static function (EloquentBuilder|QueryBuilder $target, string $column) use ($filter, $operator): void {
            $operator->apply($target, $column, $filter->value, $filter->type);
        });
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     */
    private function order(EloquentBuilder|QueryBuilder $query, SourceDefinition $source, QueryPlan $plan): void
    {
        $key = $this->keyColumn($query, $source);
        $ordered = [];

        foreach ($plan->sorts as ['column' => $column, 'direction' => $direction]) {
            // Relationship aggregate aliases are select aliases, never table columns.
            $column = $query instanceof EloquentBuilder && ! isset($source->aggregates[$column]) ? $query->qualifyColumn($column) : $column;
            $query->orderBy($column, $direction->value);
            $ordered[] = $column;
        }

        // A unique last ordering makes every page deterministic, and cursor pagination requires it.
        if (($this->config->stableOrdering || $plan->mode === PaginationMode::Cursor) && ! in_array($key, $ordered, true)) {
            $last = $plan->sorts === [] ? SortDirection::Ascending : $plan->sorts[array_key_last($plan->sorts)]['direction'];
            $query->orderBy($key, $plan->mode === PaginationMode::Cursor ? $last->value : SortDirection::Ascending->value);
        }
    }
}
