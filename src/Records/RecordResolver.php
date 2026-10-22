<?php

declare(strict_types=1);

namespace Gcego\Data\Records;

use Gcego\Data\Caching\QueryCache;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Performance\Profiler;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Query\QueryResolver;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Security\FieldGuard;

/**
 * Resolves one record by key inside the source's permitted scope.
 *
 * The lookup starts from the same constrained query as listings (tenant
 * scope, global scopes, soft deletes, Constraints, registered
 * and context scopes), never from Model::find(). A record outside the scope
 * is "not found", exactly like a missing one. Malformed keys (a non-numeric
 * key for an integer primary key, an invalid UUID or ULID) are "not found"
 * without a query. After a record is found (or read from the cache),
 * DataAuthorizer::authorize(View, $record) runs, then field rules for that
 * record and Transform nodes.
 */
final readonly class RecordResolver
{
    public function __construct(
        private ProviderRegistry $providers,
        private QueryPipeline $pipeline,
        private QueryResolver $resolver,
        private DataAuthorizer $authorizer,
        private FieldGuard $fields,
        private QueryCache $cache,
        private Profiler $profiler,
    ) {}

    /**
     * @param  QueryInput|null  $input  Only its includes (relations and optional aggregates) are used.
     *
     * @throws InvalidQueryException
     */
    public function find(SourceDefinition $source, string|int $key, QueryContext $context, ?QueryInput $input = null): RecordResult
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof ResolvesRecords) {
            throw UnsupportedProviderException::unsupported($source->provider, 'record resolution');
        }

        $plan = $this->resolver->resolve($source, new QueryInput(includes: $input === null ? [] : $input->includes));
        $constraints = $this->pipeline->constraints($source, $context);
        $fingerprint = ['key' => [get_debug_type($key), $key], 'includes' => array_keys($plan->includes), 'aggregates' => array_map(static fn (RelationAggregate $aggregate): string => $aggregate->alias, $plan->aggregates)];

        [[$record, $status], , $duration] = $this->profiler->measure($source->name, 'find', $context->profile, fn (): array => $this->cache->remember(
            $source, $context, 'find', $fingerprint,
            static fn (): mixed => $provider->find($source, $key, $constraints, $plan->includes, $plan->aggregates),
        ));

        $status = $source->cache === null ? null : $status;
        $this->pipeline->queried($source, 'find', $context, $record === null ? 0 : 1, $duration, ['include' => $plan->applied['include'] ?? []], $status);

        if ($record === null) {
            return new RecordResult($source->name, $key, null, $status, $duration);
        }

        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::View, $context, $record);
        }

        return new RecordResult($source->name, $key, $this->pipeline->present($source, $record, $this->fields->unreadable($source, $context, $record)), $status, $duration);
    }
}
