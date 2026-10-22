<?php

declare(strict_types=1);

namespace Gcego\Data\Facades;

use Closure;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SourceProvider;
use Gcego\Data\DataManager;
use Gcego\Data\Diagnostics\DataReport;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\MutationResult;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Source;
use Gcego\Data\Transactions\TransactionOptions;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DataResult query(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static AggregateResult aggregate(Source|SourceDefinition|SourceProvider|string $source, Aggregation $aggregation, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static EloquentBuilder<Model>|QueryBuilder scopedQuery(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static RecordResult find(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array<mixed>|null $input = null)
 * @method static mixed findOrFail(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array<mixed>|null $input = null)
 * @method static MutationResult create(Source|SourceDefinition|SourceProvider|string $source, Request|array<array-key, mixed> $input, ?QueryContext $context = null)
 * @method static MutationResult update(Source|SourceDefinition|SourceProvider|string $source, string|int $key, Request|array<array-key, mixed> $input, ?QueryContext $context = null)
 * @method static MutationResult delete(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null, bool $force = false)
 * @method static MutationResult forceDelete(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null)
 * @method static MutationResult restore(Source|SourceDefinition|SourceProvider|string $source, string|int $key, ?QueryContext $context = null)
 * @method static list<MutationResult> createMany(Source|SourceDefinition|SourceProvider|string $source, list<Request|array<array-key, mixed>> $inputs, ?QueryContext $context = null)
 * @method static list<MutationResult> updateMany(Source|SourceDefinition|SourceProvider|string $source, list<string|int> $keys, Request|array<array-key, mixed> $input, ?QueryContext $context = null)
 * @method static list<MutationResult> deleteMany(Source|SourceDefinition|SourceProvider|string $source, list<string|int> $keys, ?QueryContext $context = null, bool $force = false)
 * @method static mixed transaction(Closure $callback, ?TransactionOptions $options = null)
 * @method static void invalidate(Source|SourceDefinition|SourceProvider|string ...$targets)
 * @method static SourceDefinition definition(Source|SourceDefinition|SourceProvider|string $source)
 * @method static Source source(SourceProvider|string $provider)
 * @method static void scope(QueryConstraint|Closure $constraint, ?string $target = null)
 * @method static void provider(string $name, DataProvider|string $provider, bool $override = false)
 * @method static DataReport inspect(Source|SourceDefinition|SourceProvider|string $source)
 * @method static array{sql: string, bindings: int, values?: list<mixed>} explain(Source|SourceDefinition|SourceProvider|string $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null, bool $includeBindings = false)
 *
 * @see DataManager
 */
final class Data extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DataManager::class;
    }
}
