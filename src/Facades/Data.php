<?php

declare(strict_types=1);

namespace Gcego\Data\Facades;

use Closure;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\DataManager;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Diagnostics\DataReport;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Results\RecordResult;
use Gcego\Data\Source;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DataResult query(Source|SourceDefinition $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static AggregateResult aggregate(Source|SourceDefinition $source, Aggregation $aggregation, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static EloquentBuilder<Model>|QueryBuilder scopedQuery(Source|SourceDefinition $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null)
 * @method static RecordResult find(Source|SourceDefinition $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array<mixed>|null $input = null)
 * @method static mixed findOrFail(Source|SourceDefinition $source, string|int $key, ?QueryContext $context = null, QueryInput|Request|array<mixed>|null $input = null)
 * @method static SourceDefinition definition(Source|SourceDefinition $source)
 * @method static void scope(QueryConstraint|Closure $constraint, ?string $target = null)
 * @method static void provider(string $name, DataProvider|string $provider, bool $override = false)
 * @method static DataReport inspect(Source|SourceDefinition $source)
 * @method static array{sql: string, bindings: int, values?: list<mixed>} explain(Source|SourceDefinition $source, QueryInput|Request|array<mixed>|null $input = null, ?QueryContext $context = null, bool $includeBindings = false)
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
