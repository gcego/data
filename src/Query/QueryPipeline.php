<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Caching\QueryCache;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Contracts\ProvidesScopedQueries;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SupportsAggregation;
use Gcego\Data\Contracts\SupportsCursorPagination;
use Gcego\Data\DataConfig;
use Gcego\Data\Events\SourceQueried;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Performance\LazyLoadingGuard;
use Gcego\Data\Performance\Profiler;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\PaginationResult;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Security\FieldGuard;
use Gcego\Data\Security\ScopeRegistry;
use Gcego\Data\Tenancy\TenantGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Throwable;

/**
 * Executes a source for one call:
 *
 *  1. resolve and prepare the provider (definition checks, no database)
 *  2. authorize (DataAuthorizer, before any query)
 *  3. validate request input into a QueryPlan, then remove what the caller
 *     may not read (FieldAccess): such fields cannot be filtered, sorted or searched
 *  4. collect mandatory constraints: the tenant scope, the source's Query
 *     nodes, registered scopes, then QueryContext constraints
 *  5. the provider builds and runs the query (filters, search, sort, eager
 *     loading, aggregates, pagination), or the result cache answers (Cached)
 *  6. per record: redact unreadable fields, guard lazy loading, apply Transform nodes
 *  7. return a DataResult with timing, cache status and, when asked, a profile
 *
 * Each call has its own plan and query; only Cached sources reuse results.
 */
final readonly class QueryPipeline
{
    public function __construct(
        private ProviderRegistry $providers,
        private QueryResolver $resolver,
        private ScopeRegistry $scopes,
        private DataAuthorizer $authorizer,
        private TenantGuard $tenants,
        private FieldGuard $fields,
        private QueryCache $cache,
        private Profiler $profiler,
        private DataConfig $config,
        private Dispatcher $events,
        private Container $container,
    ) {}

    /**
     * The aggregate counterpart of run(): same authorization, tenant scope, constraints, scopes and request filters; pagination is ignored.
     */
    public function aggregate(SourceDefinition $source, QueryInput $input, QueryContext $context, Aggregation $aggregation): AggregateResult
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof SupportsAggregation) {
            throw UnsupportedProviderException::unsupported($source->provider, 'aggregation');
        }

        $this->authorize($source, $context);
        $plan = $this->plan($source, $input, $context);
        $denied = $this->fields->unreadable($source, $context);

        foreach ([$aggregation->column, $aggregation->groupBy, $aggregation->dateColumn] as $field) {
            if ($field !== null && in_array($field, $denied, true)) {
                throw new AuthorizationException(sprintf('You may not aggregate the "%s" field.', $field));
            }
        }

        $constraints = $this->constraints($source, $context);
        $fingerprint = [...$this->fingerprint($plan), 'aggregation' => (array) $aggregation];

        [[$result, $status], , $duration] = $this->profiler->measure($source->name, 'aggregate', $context->profile, fn (): array => $this->cache->remember(
            $source, $context, 'aggregate', $fingerprint,
            static fn (): AggregateResult => $provider->aggregate($source, $plan, $constraints, $aggregation),
        ));

        $this->queried($source, 'aggregate', $context, 1, $duration, $plan->applied, $status);

        /** @var AggregateResult $result */
        return $result;
    }

    /**
     * The rows a request may see as an unordered builder, after view authorization (see ProvidesScopedQueries).
     *
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    public function scoped(SourceDefinition $source, QueryInput $input, QueryContext $context): EloquentBuilder|QueryBuilder
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof ProvidesScopedQueries) {
            throw UnsupportedProviderException::unsupported($source->provider, 'scoped queries');
        }

        $this->authorize($source, $context);

        return $provider->scoped($source, $this->plan($source, $input, $context), $this->constraints($source, $context));
    }

    public function run(SourceDefinition $source, QueryInput $input, QueryContext $context): DataResult
    {
        $provider = $this->providers->for($source);

        if ($source->pagination->mode === PaginationMode::Cursor && ! $provider instanceof SupportsCursorPagination) {
            throw UnsupportedProviderException::unsupported($source->provider, 'cursor pagination');
        }

        $this->authorize($source, $context);

        $plan = $this->plan($source, $input, $context);
        $constraints = $this->constraints($source, $context);
        $cacheable = $plan->mode !== PaginationMode::Cursor && $this->cache->applies($source, $context);

        $denied = $this->fields->unreadable($source, $context);

        // Presentation (field redaction, transforms) is measured too: a transform that
        // lazy loads a relation per record is the usual N+1.
        [[$paginator, $status, $pagination, $items], $profile, $duration] = $this->profiler->measure($source->name, 'query', $context->profile, function () use ($provider, $source, $plan, $constraints, $context, $cacheable, $denied): array {
            if ($cacheable) {
                [$packed, $status] = $this->cache->remember($source, $context, 'query', $this->fingerprint($plan), static fn (): array => self::pack($provider->fetch($source, $plan, $constraints)));
                $paginator = self::unpack(is_array($packed) ? $packed : []);
            } else {
                $paginator = $provider->fetch($source, $plan, $constraints);
                $status = $source->cache === null ? null : QueryCache::Bypass;
            }

            // Before transforms: cursors are computed from the ordering columns of the original records.
            $pagination = PaginationResult::fromPaginator($paginator);
            $items = array_map(fn (mixed $item): mixed => $this->present($source, $item, $denied), array_values($paginator->items()));

            if (($source->transforms !== [] || $denied !== []) && $pagination->mode !== PaginationMode::Cursor) {
                $paginator->setCollection($paginator->getCollection()->make($items));
            }

            return [$paginator, $status, $pagination, $items];
        });

        /** @var AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed> $paginator */
        /** @var PaginationResult $pagination */
        /** @var list<mixed> $items */
        $this->queried($source, 'query', $context, count($items), $duration, [...$plan->applied, 'search' => $plan->terms], $status);

        return new DataResult(
            source: $source->name,
            provider: $source->provider,
            items: $items,
            pagination: $pagination,
            paginator: $paginator,
            applied: [...$plan->applied, 'search' => $plan->terms],
            durationMs: $duration,
            cache: $status,
            profile: $profile,
        );
    }

    /**
     * Mandatory constraints in order: tenant scope, the source's Constraints queries, registered scopes, context constraints.
     *
     * @return list<QueryConstraint>
     */
    public function constraints(SourceDefinition $source, QueryContext $context): array
    {
        $tenant = $this->tenants->constraint($source, $context);

        return [...($tenant === null ? [] : [$tenant]), ...$source->constraints, ...$this->scopes->for($source), ...$context->constraints];
    }

    /**
     * A record as the caller may see it: unreadable fields removed, lazy loading guarded, transforms applied.
     *
     * @param  list<string>  $denied
     */
    public function present(SourceDefinition $source, mixed $item, array $denied = []): mixed
    {
        $item = FieldGuard::redact($item, $denied);

        if ($this->config->preventLazyLoading) {
            LazyLoadingGuard::apply($item);
        }

        return $this->transform($source, $item);
    }

    public function transform(SourceDefinition $source, mixed $item): mixed
    {
        foreach ($source->transforms as $transform) {
            $item = $transform->transform($item);
        }

        return $item;
    }

    /**
     * The validated plan, without what the caller may not read.
     */
    public function plan(SourceDefinition $source, QueryInput $input, QueryContext $context): QueryPlan
    {
        $plan = $this->resolver->resolve($source, $input);
        $denied = $this->fields->unreadable($source, $context);

        if ($denied === []) {
            return $plan;
        }

        foreach ($plan->filters as $filter) {
            if (in_array($filter->field, $denied, true)) {
                throw InvalidFilterException::invalid('filter', 'uses a field you may not read.');
            }
        }

        foreach ($plan->sorts as ['column' => $column]) {
            if (in_array($column, $denied, true)) {
                throw InvalidSortException::invalid('sort', 'uses a field you may not read.');
            }
        }

        return $source->search === null ? $plan : $plan->withSearchFields(array_values(array_diff($plan->searchFields ?? $source->search->fields, $denied)));
    }

    /**
     * Everything in a plan that changes the result (for cache keys).
     *
     * @return array<string, mixed>
     */
    public function fingerprint(QueryPlan $plan): array
    {
        return [
            'filters' => array_map(static fn ($filter): array => $filter->normalize(), $plan->filters),
            'custom' => array_map(static fn (array $custom): array => [$custom['filter']->key, $custom['value']], $plan->customFilters),
            'terms' => $plan->terms,
            'search' => $plan->searchFields,
            'sorts' => array_map(static fn (array $sort): array => [$sort['column'], $sort['direction']->value], $plan->sorts),
            'includes' => array_keys($plan->includes),
            'aggregates' => array_map(static fn (RelationAggregate $aggregate): string => $aggregate->alias, $plan->aggregates),
            'mode' => $plan->mode->value,
            'per_page' => $plan->perPage,
            'page' => $plan->page,
        ];
    }

    /**
     * Dispatch SourceQueried when read events are on.
     *
     * @param  array<string, list<string>>  $applied
     */
    public function queried(SourceDefinition $source, string $operation, QueryContext $context, int $count, float $duration, array $applied = [], ?string $cache = null): void
    {
        if (! $this->config->readEvents) {
            return;
        }

        /** @var 'query'|'find'|'aggregate' $operation */
        $this->events->dispatch(new SourceQueried($source->name, $operation, $this->user($context), $this->tenantOf($context), $count, $duration, $applied, $cache));
    }

    public function user(QueryContext $context): ?Authenticatable
    {
        return $context->user ?? ($this->container->bound(AuthFactory::class) ? $this->container->make(AuthFactory::class)->guard()->user() : null);
    }

    private function tenantOf(QueryContext $context): string|int|null
    {
        try {
            return $this->tenants->tenant($context);
        } catch (Throwable) {
            return null;
        }
    }

    private function authorize(SourceDefinition $source, QueryContext $context): void
    {
        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::ViewAny, $context);
        }
    }

    /**
     * @param  AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>  $paginator
     * @return array{mode: string, items: list<mixed>, per_page: int, page: int, total: int|null, has_more: bool}
     */
    private static function pack(AbstractPaginator|AbstractCursorPaginator $paginator): array
    {
        $pagination = PaginationResult::fromPaginator($paginator);

        return [
            'mode' => $pagination->mode->value,
            'items' => array_values($paginator->items()),
            'per_page' => $pagination->perPage,
            'page' => $pagination->currentPage ?? 1,
            'total' => $pagination->total,
            'has_more' => $pagination->hasMorePages,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $packed
     * @return LengthAwarePaginator<int, mixed>|Paginator<int, mixed>
     */
    private static function unpack(array $packed): LengthAwarePaginator|Paginator
    {
        $items = is_array($packed['items'] ?? null) ? array_values($packed['items']) : [];
        $perPage = is_int($packed['per_page'] ?? null) ? max(1, $packed['per_page']) : max(1, count($items));
        $page = is_int($packed['page'] ?? null) ? $packed['page'] : 1;
        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];

        if (($packed['mode'] ?? null) === PaginationMode::Simple->value) {
            return (new Paginator($items, $perPage, $page, $options))->hasMorePagesWhen(($packed['has_more'] ?? false) === true);
        }

        return new LengthAwarePaginator($items, is_int($packed['total'] ?? null) ? $packed['total'] : count($items), $perPage, $page, $options);
    }
}
