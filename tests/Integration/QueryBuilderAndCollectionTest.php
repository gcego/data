<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Filters\ValueType;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Property;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\Sortable;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedSubjects());

it('queries tables and returns rows as arrays', function (): void {
    $result = systemQuery(Source::table('subjects', new Property(Source::Columns, ['id', 'name', 'level']), new Filter('deleted_at', operator: FilterOperator::Null), new Search('name'), new Sortable('name'), Paginate::simple(2)), ['search' => 'o', 'sort' => '-name']);

    expect($result->items())->toBe([
        ['id' => 5, 'name' => 'Economics', 'level' => 3],
        ['id' => 2, 'name' => 'Biology', 'level' => 2],
    ])
        ->and($result->provider)->toBe('query')
        ->and($result->pagination->hasMorePages)->toBeFalse();
});

it('calls trusted builder factories for every execution, keeping their bindings', function (): void {
    $calls = 0;
    $source = Source::builder(function () use (&$calls) {
        $calls++;

        return DB::table('subjects')
            ->join('topics', 'topics.subject_id', '=', 'subjects.id')
            ->where('topics.name', 'like', '%Practice')
            ->select('subjects.id', 'subjects.name', 'topics.name as topic');
    }, new Property(Source::Key, 'subjects.id'), new Filterable('level', type: ValueType::Integer, column: 'subjects.level'), new Sort('subjects.name'));

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
    expect(fn () => systemQuery(Source::builder(fn () => Subject::query())))->toThrow(InvalidSourceException::class, 'use new Source(Model::class)')
        ->and(fn () => systemQuery(Source::table('subjects', new With('topics'))))->toThrow(InvalidSourceException::class, 'need an Eloquent model source');
});

it('resolves query-builder records by the configured key', function (): void {
    $source = Source::table('subjects', new Property(Source::Key, 'code'));

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
    $source = Source::collection(fn () => new Collection($items), new Filterable('weeks', FilterOperator::comparisons(), ValueType::Integer), new Search('name'), new Sortable('weeks', 'name'), new Paginate(2));

    expect(pluck(systemQuery($source, ['filter' => ['weeks' => ['gte' => '10']], 'sort' => '-weeks']), 'id'))->toBe([1, 3])
        ->and(systemQuery($source, ['filter' => ['weeks' => ['gte' => '10']], 'sort' => '-weeks'])->pagination->total)->toBe(3)
        ->and(pluck(systemQuery($source, ['search' => 'term', 'page' => 2]), 'name'))->toBe(['Term 3'])
        ->and(pluck(systemQuery($source, ['sort' => 'name']), 'name'))->toBe(['Holiday', 'Term 1'])
        ->and($items)->toBe($original)
        ->and(app(DataManager::class)->find($source, '2', QueryContext::system())->record())->toBe($items[1]);
});

it('applies collection constraints and rejects non-collection results', function (): void {
    $items = [['id' => 1, 'school' => 1], ['id' => 2, 'school' => 2]];

    expect(pluck(systemQuery(Source::collection($items, new Query(fn (Collection $rows) => $rows->where('school', 1)))), 'id'))->toBe([1])
        ->and(fn () => systemQuery(Source::collection($items, new Query(fn (Collection $rows) => null))))->toThrow(InvalidSourceException::class, 'must return the filtered collection')
        ->and(fn () => systemQuery(Source::collection(fn () => 'nope')))->toThrow(InvalidSourceException::class, 'must return an array');
});

it('keeps the original collection order for equal sort values', function (): void {
    $items = array_map(fn (int $index): array => ['id' => 100 - $index, 'group' => $index % 2], range(1, 10));
    $result = systemQuery(Source::collection($items, new Sort('group'), new Paginate(10)));

    expect(pluck($result, 'id'))->toBe([90, 92, 94, 96, 98, 91, 93, 95, 97, 99]);
});

it('supports simple pagination for collections', function (): void {
    $result = systemQuery(Source::collection(array_map(fn (int $id): array => ['id' => $id], range(1, 5)), Paginate::simple(2)), ['page' => 2]);

    expect(pluck($result, 'id'))->toBe([3, 4])
        ->and($result->pagination->hasMorePages)->toBeTrue()
        ->and($result->pagination->total)->toBeNull();
});
