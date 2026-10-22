<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Contracts\ProvidesScopedQueries;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SupportsAggregation;
use Gcego\Data\Contracts\SupportsCursorPagination;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\PaginationResult;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Security\ScopeRegistry;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Executes a source for one call:
 *
 *  1. resolve and prepare the provider (definition checks, no database)
 *  2. authorize (DataAuthorizer, before any query)
 *  3. validate request input into a QueryPlan
 *  4. collect mandatory constraints: the source's Query nodes, then
 *     registered scopes, then QueryContext constraints
 *  5. the provider builds and runs the query (filters, search, sort, eager loading, pagination)
 *  6. apply Transform nodes and return a DataResult
 *
 * Nothing is cached between calls; each call has its own plan and query.
 */
final readonly class QueryPipeline
{
    public function __construct(
        private ProviderRegistry $providers,
        private QueryResolver $resolver,
        private ScopeRegistry $scopes,
        private DataAuthorizer $authorizer,
    ) {}

    /**
     * The aggregate counterpart of run(): same authorization, constraints, scopes and request filters; pagination is ignored.
     */
    public function aggregate(SourceDefinition $source, QueryInput $input, QueryContext $context, Aggregation $aggregation): AggregateResult
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof SupportsAggregation) {
            throw UnsupportedProviderException::unsupported($source->provider, 'aggregation');
        }

        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::ViewAny, $context);
        }

        return $provider->aggregate($source, $this->resolver->resolve($source, $input), $this->constraints($source, $context), $aggregation);
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

        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::ViewAny, $context);
        }

        return $provider->scoped($source, $this->resolver->resolve($source, $input), $this->constraints($source, $context));
    }

    public function run(SourceDefinition $source, QueryInput $input, QueryContext $context): DataResult
    {
        $provider = $this->providers->for($source);

        if ($source->pagination->mode === PaginationMode::Cursor && ! $provider instanceof SupportsCursorPagination) {
            throw UnsupportedProviderException::unsupported($source->provider, 'cursor pagination');
        }

        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::ViewAny, $context);
        }

        $plan = $this->resolver->resolve($source, $input);
        $started = hrtime(true);
        $paginator = $provider->fetch($source, $plan, $this->constraints($source, $context));
        // Before transforms: cursors are computed from the ordering columns of the original records.
        $pagination = PaginationResult::fromPaginator($paginator);
        $items = array_map(fn (mixed $item): mixed => $this->transform($source, $item), array_values($paginator->items()));

        if ($source->transforms !== [] && $pagination->mode !== PaginationMode::Cursor) {
            $paginator->setCollection($paginator->getCollection()->make($items));
        }

        return new DataResult(
            source: $source->name,
            provider: $source->provider,
            items: $items,
            pagination: $pagination,
            paginator: $paginator,
            applied: [...$plan->applied, 'search' => $plan->terms],
            durationMs: round((hrtime(true) - $started) / 1e6, 3),
        );
    }

    /**
     * @return list<QueryConstraint>
     */
    public function constraints(SourceDefinition $source, QueryContext $context): array
    {
        return [...$source->constraints, ...$this->scopes->for($source), ...$context->constraints];
    }

    public function transform(SourceDefinition $source, mixed $item): mixed
    {
        foreach ($source->transforms as $transform) {
            $item = $transform->transform($item);
        }

        return $item;
    }
}
