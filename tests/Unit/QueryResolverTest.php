<?php

declare(strict_types=1);

use Gcego\Data\Compilation\SourceCompiler;
use Gcego\Data\DataConfig;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Between;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\GreaterThanOrEqual;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\IsNotNull;
use Gcego\Data\Filters\Operators\IsNull;
use Gcego\Data\Filters\Options;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPlan;
use Gcego\Data\Query\QueryResolver;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;

/**
 * @param  array<mixed>  $input
 */
function plan(array $input, ?DataConfig $config = null, ?Source $source = null): QueryPlan
{
    $source ??= Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('status', [StringType::make(), Equal::make(), In::make(), Options::make(['draft', 'published'])]), Filterable::make('level', [Integer::make(), Equal::make(), GreaterThanOrEqual::make(), Between::make(), In::make()]), Filterable::make('code', [StringType::make(), IsNull::make(), IsNotNull::make()]), Filterable::make('subject', [Integer::make(), Equal::make(), In::make(), Column::make('subject_id')])]), Search::make(['name']), Sort::make('level', 'desc'), Sortables::make(['name', 'newest' => 'created_at']), Includables::make(['topics']), Pagination::make(10, max: 40));

    return (new QueryResolver($config ?? new DataConfig))->resolve((new SourceCompiler)->compile($source), QueryInput::fromArray($input));
}

it('maps allowlisted filters to trusted columns and cast values', function (): void {
    $filters = array_map(fn (Filter $filter): array => $filter->normalize(), plan(['filter' => [
        'status' => 'published',
        'level' => ['gte' => '2', 'between' => '1,3'],
        'code' => ['null' => '1'],
        'subject' => '7',
        'level.in' => null,
    ]], new DataConfig(strict: false))->filters);

    expect($filters)->toBe([
        ['field' => 'status', 'operator' => 'eq', 'value' => 'published'],
        ['field' => 'level', 'operator' => 'gte', 'value' => 2],
        ['field' => 'level', 'operator' => 'between', 'value' => [1, 3]],
        ['field' => 'code', 'operator' => 'null', 'value' => null],
        ['field' => 'subject_id', 'operator' => 'eq', 'value' => 7],
    ]);
});

it('treats empty filter values as no filter', function (): void {
    expect(plan(['filter' => ['status' => '', 'level' => ['in' => []]]])->filters)->toBe([]);
});

it('rejects filters outside the allowlist', function (array $filter, string $exception, string $message): void {
    expect(fn () => plan(['filter' => $filter]))->toThrow($exception, $message);
})->with([
    'unknown field' => [['password' => 'x'], InvalidFilterException::class, '"filter[password]" is not a filterable field. Allowed: status, level, code, subject.'],
    'column name, not key' => [['subject_id' => '7'], InvalidFilterException::class, 'not a filterable field'],
    'operator not allowed' => [['status' => ['gt' => 'a']], InvalidFilterException::class, 'does not support the "gt" operator. Allowed: eq, in.'],
    'unknown operator' => [['status' => ['like' => '%a%']], InvalidFilterException::class, 'does not support the "like" operator'],
    'not an option' => [['status' => 'secret'], InvalidFilterException::class, 'not one of the allowed options'],
    'wrong type' => [['level' => '1 OR 1=1'], InvalidFilterException::class, 'must be a valid integer'],
    'between arity' => [['level' => ['between' => '1']], InvalidFilterException::class, 'exactly two values'],
    'single with list' => [['level' => ['eq' => ['1', '2']]], InvalidFilterException::class, 'single value'],
    'null with value' => [['code' => ['null' => 'abc']], InvalidFilterException::class, 'takes no value'],
]);

it('limits the number of in values', function (): void {
    expect(fn () => plan(['filter' => ['level' => array_map('strval', range(1, 101))]]))->toThrow(InvalidFilterException::class, 'between 1 and 100 values');
});

it('ignores unknown keys only when not strict', function (): void {
    $config = new DataConfig(strict: false);

    expect(plan(['filter' => ['password' => 'x'], 'sort' => 'password', 'include' => 'secrets'], $config)->filters)->toBe([])
        ->and(plan(['sort' => 'password'], $config)->sorts)->toBe([['column' => 'level', 'direction' => SortDirection::Descending]]);
});

it('resolves sorting from the allowlist, falling back to the defaults', function (): void {
    expect(plan(['sort' => '-newest,name'])->sorts)->toBe([['column' => 'created_at', 'direction' => SortDirection::Descending], ['column' => 'name', 'direction' => SortDirection::Ascending]])
        ->and(plan([])->sorts)->toBe([['column' => 'level', 'direction' => SortDirection::Descending]])
        ->and(plan(['sort' => 'name,-name'])->sorts)->toHaveCount(1)
        ->and(fn () => plan(['sort' => 'created_at']))->toThrow(InvalidSortException::class, 'not a sortable field. Allowed: name, newest.')
        ->and(fn () => plan(['sort' => 'name;drop table subjects']))->toThrow(InvalidSortException::class)
        ->and(fn () => plan(['sort' => 'a,b,c,d']))->toThrow(InvalidSortException::class, 'more than 3 fields');
});

it('limits search input', function (): void {
    expect(plan(['search' => 'linear  algebra linear'])->terms)->toBe(['linear', 'algebra'])
        ->and(plan([])->terms)->toBe([])
        ->and(fn () => plan(['search' => str_repeat('a', 201)]))->toThrow(InvalidQueryException::class, 'longer than 200 characters')
        ->and(fn () => plan(['search' => 'a b c d e f g h i']))->toThrow(InvalidQueryException::class, 'more than 8 words')
        ->and(fn () => plan(['search' => "a\x00b"]))->toThrow(InvalidQueryException::class, 'control characters')
        ->and(fn () => plan(['search' => 'x'], source: Source::define(Model::make(Subject::class))))->toThrow(InvalidQueryException::class, 'not supported');
});

it('bounds pagination', function (): void {
    expect(plan([])->perPage)->toBe(10)
        ->and(plan(['per_page' => 25])->perPage)->toBe(25)
        ->and(plan(['per_page' => 1000])->perPage)->toBe(40)
        ->and(plan(['per_page' => 1000], source: Source::define(Model::make(Subject::class)))->perPage)->toBe(100)
        ->and(plan([], source: Source::define(Model::make(Subject::class)))->perPage)->toBe(25)
        ->and(plan(['page' => 3])->page)->toBe(3)
        ->and(plan([], new DataConfig(defaultPerPage: 5, maxPerPage: 8), Source::define(Model::make(Subject::class), Pagination::make(50)))->perPage)->toBe(8)
        ->and(fn () => plan(['page' => 999999999], new DataConfig(maxOffset: null), Source::define(Model::make(Subject::class), Pagination::make(10))))->not->toThrow(InvalidQueryException::class)
        ->and(fn () => plan(['page' => 10002], source: Source::define(Model::make(Subject::class), Pagination::make(10))))->toThrow(InvalidQueryException::class, 'beyond the deepest page')
        ->and(fn () => plan(['cursor' => 'abc']))->toThrow(InvalidQueryException::class, 'not supported by this source');
});

it('accepts only allowlisted includes', function (): void {
    expect(array_keys(plan(['include' => 'topics'])->includes))->toBe(['topics'])
        ->and(fn () => plan(['include' => 'topics.lessons']))->toThrow(InvalidQueryException::class, 'not an allowed include. Allowed: topics.')
        ->and(fn () => plan(['include' => 'password']))->toThrow(InvalidQueryException::class);
});

it('validates its configuration', function (): void {
    expect(DataConfig::fromArray(require dirname(__DIR__, 2).'/config/gcego-data.php'))->toEqual(new DataConfig)
        ->and(fn () => DataConfig::fromArray(['pagination' => ['max_per_page' => 0]]))->toThrow(InvalidSourceException::class, 'pagination.max_per_page must be a positive integer')
        ->and(fn () => DataConfig::fromArray(['pagination' => ['default_per_page' => 50, 'max_per_page' => 10]]))->toThrow(InvalidSourceException::class, 'must not exceed')
        ->and(fn () => DataConfig::fromArray(['queries' => ['strict' => 'yes']]))->toThrow(InvalidSourceException::class, 'queries.strict must be a boolean');
});
