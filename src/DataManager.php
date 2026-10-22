<?php

declare(strict_types=1);

namespace Gcego\Data;

use Closure;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Caching\QueryCache;
use Gcego\Data\Compilation\SourceCompiler;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SourceProvider;
use Gcego\Data\Diagnostics\DataReport;
use Gcego\Data\Diagnostics\QueryInspector;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\MissingTenantException;
use Gcego\Data\Exceptions\MutationException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Mutations\MutationPipeline;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Records\RecordResolver;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\MutationResult;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Security\ScopeRegistry;
use Gcego\Data\Transactions\TransactionManager;
use Gcego\Data\Transactions\TransactionOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The entry point of gcego/data (a container singleton, also the Data facade):
 * the execution engine for Source definitions; definitions never execute themselves.
 * the one way every GCEGO package reads and writes application data.
 *
 *     $page = Data::query(EmployeesSource::class, $request);                 // a SourceProvider
 *     $page = Data::query(Source::define(Model::make(Subject::class)), $request);
 *     $subject = Data::findOrFail($source, $id);                          // scoped, authorized lookup
 *     $page = Data::query($source, ['search' => 'alg'], QueryContext::for($user)->constrain($scope));
 *
 *     Data::create($source, $request->all());                             // validated, authorized, atomic
 *     Data::update($source, $id, ['name' => 'Algebra II']);
 *     Data::delete($source, $id);
 *     Data::transaction(fn () => ...);                                    // several writes, all or nothing
 *
 * Every read and write is authorized (DataAuthorizer) unless the context is
 * QueryContext::system(), and limited to the current tenant for
 * tenant-scoped sources. It holds no request state.
 */
final readonly class DataManager
{
    public function __construct(
        private SourceCompiler $compiler,
        private QueryPipeline $pipeline,
        private RecordResolver $records,
        private ProviderRegistry $providers,
        private ScopeRegistry $scopes,
        private QueryInspector $inspector,
        private MutationPipeline $mutations,
        private TransactionManager $transactions,
        private QueryCache $cache,
        private Container $container,
    ) {}

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Untrusted parameters (filter, search, sort, page, per_page, cursor, include).
     *
     * @throws InvalidQueryException
     */
    public function query(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): DataResult
    {
        return $this->pipeline->run($this->definition($source), self::input($input), $context ?? new QueryContext);
    }

    /**
     * An aggregate (count, sum, average, minimum, maximum; optionally grouped
     * by a column or a time bucket) computed by the provider (the database for
     * Eloquent and query builder sources) under the same authorization,
     * mandatory constraints, scopes and allowlisted request filters as query().
     *
     * @param  QueryInput|Request|array<mixed>|null  $input  Filters and search only.
     */
    public function aggregate(Source|SourceDefinition|SourceProvider|string $source, Aggregation $aggregation, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): AggregateResult
    {
        return $this->pipeline->aggregate($this->definition($source), self::input($input), $context ?? new QueryContext, $aggregation);
    }

    /**
     * The rows this request may see, as an unordered builder to aggregate on (trusted callers only):
     * view authorization, mandatory scopes (tenancy), fixed filters, request filters and search are applied.
     *
     * @param  QueryInput|Request|array<mixed>|null  $input
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    public function scopedQuery(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): EloquentBuilder|QueryBuilder
    {
        return $this->pipeline->scoped($this->definition($source), self::input($input), $context ?? new QueryContext);
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Only "include" is used.
     */
    public function find(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array|null $input = null): RecordResult
    {
        return $this->records->find($this->definition($source), $key, $context ?? new QueryContext, self::input($input));
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Only "include" is used.
     *
     * @throws RecordNotFoundException
     */
    public function findOrFail(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array|null $input = null): mixed
    {
        return $this->find($source, $key, $context, $input)->orFail();
    }

    /**
     * Create a record from untrusted input (only Writable fields, validated).
     *
     * @param  Request|array<array-key, mixed>  $input
     *
     * @throws ValidationException|AuthorizationException|MutationException|MissingTenantException
     */
    public function create(Source|SourceDefinition|SourceProvider|string $source, Request|array $input, ?QueryContext $context = null): MutationResult
    {
        return $this->mutations->create($this->definition($source), self::attributes($input), $context ?? new QueryContext);
    }

    /**
     * Update the record with this key, inside the source's scope, from untrusted input (partial).
     *
     * @param  Request|array<array-key, mixed>  $input
     *
     * @throws ValidationException|AuthorizationException|MutationException|RecordNotFoundException
     */
    public function update(Source|SourceDefinition|SourceProvider|string $source, string|int $key, Request|array $input, ?QueryContext $context = null): MutationResult
    {
        return $this->mutations->update($this->definition($source), $key, self::attributes($input), $context ?? new QueryContext);
    }

    /**
     * Delete the record with this key (a soft delete for SoftDeletes models; $force deletes permanently).
     *
     * @throws AuthorizationException|MutationException|RecordNotFoundException
     */
    public function delete(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, bool $force = false): MutationResult
    {
        return $this->mutations->delete($this->definition($source), $key, $context ?? new QueryContext, $force);
    }

    /**
     * @throws AuthorizationException|MutationException|RecordNotFoundException
     */
    public function forceDelete(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null): MutationResult
    {
        return $this->delete($source, $key, $context, true);
    }

    /**
     * Restore a soft-deleted record.
     *
     * @throws AuthorizationException|MutationException|RecordNotFoundException
     */
    public function restore(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null): MutationResult
    {
        return $this->mutations->restore($this->definition($source), $key, $context ?? new QueryContext);
    }

    /**
     * Create several records atomically: all are created, or none.
     *
     * @param  list<Request|array<array-key, mixed>>  $inputs
     * @return list<MutationResult>
     */
    public function createMany(Source|SourceDefinition|SourceProvider|string $source, array $inputs, ?QueryContext $context = null): array
    {
        $definition = $this->definition($source);

        return $this->mutations->batch($definition, fn (): array => array_map(fn (Request|array $input): MutationResult => $this->create($definition, $input, $context), array_values($inputs)));
    }

    /**
     * Apply the same update to several records atomically (each one authorized and scoped).
     *
     * @param  list<string|int>  $keys
     * @param  Request|array<array-key, mixed>  $input
     * @return list<MutationResult>
     */
    public function updateMany(Source|SourceDefinition|SourceProvider|string $source, array $keys, Request|array $input, ?QueryContext $context = null): array
    {
        $definition = $this->definition($source);

        return $this->mutations->batch($definition, fn (): array => array_map(fn (string|int $key): MutationResult => $this->update($definition, $key, $input, $context), array_values($keys)));
    }

    /**
     * Delete several records atomically (each one authorized and scoped).
     *
     * @param  list<string|int>  $keys
     * @return list<MutationResult>
     */
    public function deleteMany(Source|SourceDefinition|SourceProvider|string $source, array $keys, ?QueryContext $context = null, bool $force = false): array
    {
        $definition = $this->definition($source);

        return $this->mutations->batch($definition, fn (): array => array_map(fn (string|int $key): MutationResult => $this->delete($definition, $key, $context, $force), array_values($keys)));
    }

    /**
     * Run a unit of work atomically, with rollback and deadlock retries.
     *
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $callback
     * @return T
     */
    public function transaction(Closure $callback, ?TransactionOptions $options = null): mixed
    {
        return $this->transactions->run($callback, $options);
    }

    /**
     * Invalidate cached results that depend on a source (or SourceProvider), a model class or a "table:connection:name".
     */
    public function invalidate(Source|SourceDefinition|SourceProvider|string ...$targets): void
    {
        foreach ($targets as $target) {
            is_string($target) && ! is_subclass_of($target, SourceProvider::class)
                ? $this->cache->invalidate($target)
                : $this->cache->invalidateSource($this->definition($target));
        }
    }

    /**
     * The compiled definition of a Source, a SourceProvider, or a SourceProvider
     * class name (resolved through the container).
     *
     * @param  Source|SourceDefinition|SourceProvider|string  $source  A SourceProvider class name is checked before it is resolved.
     *
     * @throws InvalidSourceException
     */
    public function definition(Source|SourceDefinition|SourceProvider|string $source): SourceDefinition
    {
        return match (true) {
            $source instanceof SourceDefinition => $source,
            $source instanceof Source => $this->compiler->compile($source),
            default => $this->compiler->compile($this->source($source)),
        };
    }

    /**
     * The Source a provider declares.
     *
     * @param  SourceProvider|string  $provider  A class name must implement SourceProvider.
     *
     * @throws InvalidSourceException
     */
    public function source(SourceProvider|string $provider): Source
    {
        if (is_string($provider)) {
            // Checked before the container instantiates anything.
            if (! is_subclass_of($provider, SourceProvider::class)) {
                throw InvalidSourceException::invalidDefinition('Source provider', sprintf('"%s" does not implement %s.', mb_substr($provider, 0, 120), SourceProvider::class));
            }

            $provider = $this->container->make($provider);
        }

        return $provider instanceof SourceProvider
            ? $provider->source()
            : throw InvalidSourceException::invalidDefinition('Source provider', sprintf('%s does not implement %s.', get_debug_type($provider), SourceProvider::class));
    }

    /**
     * Register an application-wide mandatory scope (see ScopeRegistry).
     *
     * @param  class-string|null  $target  A model class or interface; null for every source.
     */
    public function scope(QueryConstraint|Closure $constraint, ?string $target = null): void
    {
        $this->scopes->register($constraint, $target);
    }

    /**
     * @param  DataProvider|class-string<DataProvider>  $provider
     */
    public function provider(string $name, DataProvider|string $provider, bool $override = false): void
    {
        $this->providers->register($name, $provider, $override);
    }

    public function inspect(Source|SourceDefinition|SourceProvider|string $source): DataReport
    {
        return $this->inspector->inspect($source instanceof Source || $source instanceof SourceDefinition ? $source : $this->source($source));
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input
     * @return array{sql: string, bindings: int, values?: list<mixed>}
     */
    public function explain(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null, bool $includeBindings = false): array
    {
        return $this->inspector->explain($source instanceof Source || $source instanceof SourceDefinition ? $source : $this->source($source), self::input($input), $context, $includeBindings);
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input
     */
    private static function input(QueryInput|Request|array|null $input): QueryInput
    {
        return match (true) {
            $input instanceof QueryInput => $input,
            $input instanceof Request => QueryInput::fromRequest($input),
            is_array($input) => QueryInput::fromArray($input),
            default => QueryInput::none(),
        };
    }

    /**
     * @param  Request|array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private static function attributes(Request|array $input): array
    {
        return $input instanceof Request ? $input->all() : $input;
    }
}
