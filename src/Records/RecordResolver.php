<?php

declare(strict_types=1);

namespace Gcego\Data\Records;

use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Query\QueryResolver;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Security\DataOperation;

/**
 * Resolves one record by key inside the source's permitted scope.
 *
 * The lookup starts from the same constrained query as listings (global
 * scopes, soft deletes, Query nodes, fixed filters, registered and context
 * scopes), never from Model::find(). A record outside the scope is "not
 * found", exactly like a missing one. Malformed keys (a non-numeric key for an
 * integer primary key, an invalid UUID or ULID) are "not found" without a query.
 * After a record is found, DataAuthorizer::authorize(View, $record) runs.
 */
final readonly class RecordResolver
{
    public function __construct(
        private ProviderRegistry $providers,
        private QueryPipeline $pipeline,
        private QueryResolver $resolver,
        private DataAuthorizer $authorizer,
    ) {}

    /**
     * @param  QueryInput|null  $input  Only its includes are used.
     *
     * @throws InvalidQueryException
     */
    public function find(SourceDefinition $source, string|int $key, QueryContext $context, ?QueryInput $input = null): RecordResult
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof ResolvesRecords) {
            throw UnsupportedProviderException::unsupported($source->provider, 'record resolution');
        }

        $includes = $this->resolver->resolve($source, new QueryInput(includes: $input === null ? [] : $input->includes))->includes;
        $record = $provider->find($source, $key, $this->pipeline->constraints($source, $context), $includes);

        if ($record === null) {
            return new RecordResult($source->name, $key, null);
        }

        if ($context->authorize) {
            $this->authorizer->authorize($source, DataOperation::View, $context, $record);
        }

        return new RecordResult($source->name, $key, $this->pipeline->transform($source, $record));
    }
}
