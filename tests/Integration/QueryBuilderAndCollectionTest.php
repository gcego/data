<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\BuilderFactory;
use Gcego\Data\Definitions\Columns;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Key;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Between;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\GreaterThan;
use Gcego\Data\Filters\Operators\GreaterThanOrEqual;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\IsNotNull;
use Gcego\Data\Filters\Operators\IsNull;
use Gcego\Data\Filters\Operators\LessThan;
use Gcego\Data\Filters\Operators\LessThanOrEqual;
use Gcego\Data\Filters\Operators\NotEqual;
use Gcego\Data\Filters\Operators\NotIn;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedSubjects());

it('queries tables and returns rows as arrays', function (): void {
    $result = systemQuery(Source::define(DatabaseTable::make('subjects'), Constraints::make([Filter::make('deleted_at', operator: IsNull::make())]), Search::make(['name']), Sortables::make(['name']), Columns::make(['id', 'name', 'level']), Pagination::simple(2)), ['search' => 'o', 'sort' => '-name']);

    expect($result->items())->toBe([
        ['id' => 5, 'name' => 'Economics', 'level' => 3],
        ['id' => 2, 'name' => 'Biology', 'level' => 2],
    ])
        ->and($result->provider)->toBe('query')
        ->and($result->pagination->hasMorePages)->toBeFalse();
});

it('calls trusted builder factories for every execution, keeping their bindings', function (): void {
    $calls = 0;
    $source = Source::define(BuilderFactory::make(function () use (&$calls) {
        $calls++;

        return DB::table('subjects')
            ->join('topics', 'topics.subject_id', '=', 'subjects.id')
            ->where('topics.name', 'like', '%Practice')
            ->select('subjects.id', 'subjects.name', 'topics.name as topic');
    }), Filterables::make([Filterable::make('level', [Integer::make(), Equal::make(), In::make(), Column::make('subjects.level')])]), Sort::make('subjects.name', 'asc'), Key::make('subjects.id'));

    $result = systemQuery($source, ['filter' => ['level' => '3']]);
    systemQuery($source);

    expect($result->items())->toBe([
        ['id' => 4, 'name' => 'Drama', 'topic' => 'Drama Practice'],
        ['id' => 5, 'name' => 'Economics', 'topic' => 'Economics Practice'],
    ])
        ->and($calls)->toBe(2)
        ->and(app(DataManager::class)->find($source, 4, QueryContext::system())->record())->toBe(['id' => 4, 'name' => 'Drama', 'topic' => 'Drama Practice']);
});

it('rejects Eloquent builders and relationships on query-builder sources', function (): void {
    expect(fn () => systemQuery(Source::define(BuilderFactory::make(fn () => Subject::query()))))->toThrow(InvalidSourceException::class, 'for Eloquent builders use Model::make(...)')
        ->and(fn () => systemQuery(Source::define(DatabaseTable::make('subjects'), Relations::make(['topics']))))->toThrow(InvalidSourceException::class, 'need an Eloquent model source');
});

it('resolves query-builder records by the configured key', function (): void {
    $source = Source::define(DatabaseTable::make('subjects'), Key::make('code'));

    expect(app(DataManager::class)->find($source, 'BIO101', QueryContext::system())->record()['name'] ?? null)->toBe('Biology')
        ->and(app(DataManager::class)->find($source, 'NOPE', QueryContext::system())->found())->toBeFalse();
});

it('filters, searches, sorts and paginates collections without changing them', function (): void {
    $items = [
        ['id' => 1, 'name' => 'Term 1', 'weeks' => 12, 'current' => false],
        ['id' => 2, 'name' => 'Term 2', 'weeks' => 10, 'current' => true],
        ['id' => 3, 'name' => 'Term 3', 'weeks' => 12, 'current' => false],
        ['id' => 4, 'name' => 'Holiday', 'weeks' => 6, 'current' => false],
    ];
    $original = $items;
    $source = Source::define(Items::make(fn () => new Collection($items)), Filterables::make([Filterable::make('weeks', [Integer::make(), Equal::make(), NotEqual::make(), GreaterThan::make(), GreaterThanOrEqual::make(), LessThan::make(), LessThanOrEqual::make(), In::make(), NotIn::make(), Between::make(), IsNull::make(), IsNotNull::make()])]), Search::make(['name']), Sortables::make(['weeks', 'name']), Pagination::make(2));

    expect(pluck(systemQuery($source, ['filter' => ['weeks' => ['gte' => '10']], 'sort' => '-weeks']), 'id'))->toBe([1, 3])
        ->and(systemQuery($source, ['filter' => ['weeks' => ['gte' => '10']], 'sort' => '-weeks'])->pagination->total)->toBe(3)
        ->and(pluck(systemQuery($source, ['search' => 'term', 'page' => 2]), 'name'))->toBe(['Term 3'])
        ->and(pluck(systemQuery($source, ['sort' => 'name']), 'name'))->toBe(['Holiday', 'Term 1'])
        ->and($items)->toBe($original)
        ->and(app(DataManager::class)->find($source, '2', QueryContext::system())->record())->toBe($items[1]);
});

it('applies collection constraints and rejects non-collection results', function (): void {
    $items = [['id' => 1, 'school' => 1], ['id' => 2, 'school' => 2]];

    expect(pluck(systemQuery(Source::define(Items::make($items), Constraints::make([Query::make(fn (Collection $rows) => $rows->where('school', 1))]))), 'id'))->toBe([1])
        ->and(fn () => systemQuery(Source::define(Items::make($items), Constraints::make([Query::make(fn (Collection $rows) => null)]))))->toThrow(InvalidSourceException::class, 'must return the filtered collection')
        ->and(fn () => systemQuery(Source::define(Items::make(fn () => 'nope'))))->toThrow(InvalidSourceException::class, 'must return an array');
});

it('keeps the original collection order for equal sort values', function (): void {
    $items = array_map(fn (int $index): array => ['id' => 100 - $index, 'group' => $index % 2], range(1, 10));
    $result = systemQuery(Source::define(Items::make($items), Sort::make('group', 'asc'), Pagination::make(10)));

    expect(pluck($result, 'id'))->toBe([90, 92, 94, 96, 98, 91, 93, 95, 97, 99]);
});

it('supports simple pagination for collections', function (): void {
    $result = systemQuery(Source::define(Items::make(array_map(fn (int $id): array => ['id' => $id], range(1, 5))), Pagination::simple(2)), ['page' => 2]);

    expect(pluck($result, 'id'))->toBe([3, 4])
        ->and($result->pagination->hasMorePages)->toBeTrue()
        ->and($result->pagination->total)->toBeNull();
});
