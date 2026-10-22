<?php

declare(strict_types=1);

namespace Gcego\Data;

use Closure;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Definition\SourceCompiler;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Diagnostics\DataReport;
use Gcego\Data\Diagnostics\QueryInspector;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Records\RecordResolver;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Security\ScopeRegistry;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The entry point of gcego/data (a container singleton, also the Data facade).
 *
 *     $page = Data::query(new Source(Subject::class), $request);            // authorized listing
 *     $subject = Data::findOrFail($source, $id);                          // scoped, authorized lookup
 *     $page = Data::query($source, ['search' => 'alg'], QueryContext::for($user)->constrain($scope));
 *
 * Every read is authorized (DataAuthorizer) unless the context is
 * QueryContext::system(). It holds no request state.
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
    ) {}

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Untrusted parameters (filter, search, sort, page, per_page, cursor, include).
     *
     * @throws InvalidQueryException
     */
    public function query(Source|SourceDefinition $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): DataResult
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
    public function aggregate(Source|SourceDefinition $source, Aggregation $aggregation, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): AggregateResult
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
    public function scopedQuery(Source|SourceDefinition $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null): EloquentBuilder|QueryBuilder
    {
        return $this->pipeline->scoped($this->definition($source), self::input($input), $context ?? new QueryContext);
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Only "include" is used.
     */
    public function find(Source|SourceDefinition $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array|null $input = null): RecordResult
    {
        return $this->records->find($this->definition($source), $key, $context ?? new QueryContext, self::input($input));
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input  Only "include" is used.
     *
     * @throws RecordNotFoundException
     */
    public function findOrFail(Source|SourceDefinition $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array|null $input = null): mixed
    {
        return $this->find($source, $key, $context, $input)->orFail();
    }

    public function definition(Source|SourceDefinition $source): SourceDefinition
    {
        return $source instanceof SourceDefinition ? $source : $this->compiler->compile($source);
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

    public function inspect(Source|SourceDefinition $source): DataReport
    {
        return $this->inspector->inspect($source);
    }

    /**
     * @param  QueryInput|Request|array<mixed>|null  $input
     * @return array{sql: string, bindings: int, values?: list<mixed>}
     */
    public function explain(Source|SourceDefinition $source, QueryInput|Request|array|null $input = null, ?QueryContext $context = null, bool $includeBindings = false): array
    {
        return $this->inspector->explain($source, self::input($input), $context, $includeBindings);
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
}
