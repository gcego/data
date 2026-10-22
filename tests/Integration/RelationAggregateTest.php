<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Aggregates;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\Topic;

function topicCounts(iterable $items): array
{
    $counts = [];

    foreach ($items as $item) {
        $counts[$item->name] = $item->topics_count;
    }

    return $counts;
}

it('adds relationship counts to every record in one query', function (): void {
    $subjects = seedSubjects();
    Topic::query()->create(['subject_id' => $subjects['algebra']->id, 'name' => 'Algebra Revision']);
    $source = Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')]), Sort::make('name', 'asc'));

    $queries = queriesDuring(function () use ($source, &$result): void {
        $result = systemQuery($source);
    });

    expect(topicCounts($result))->toBe(['Algebra' => 3, 'Biology' => 2, 'Chemistry' => 2, 'Drama' => 2, 'Economics' => 2])
        ->and($queries)->toHaveCount(2);
});

it('computes constrained counts, sums and existence under their aliases', function (): void {
    seedSubjects();
    $source = Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics', 'practice_topics', fn ($query) => $query->where('name', 'like', '%Practice')), RelationAggregate::max('topics', 'id', 'latest_topic_id'), RelationAggregate::exists('topics')]), Sort::make('name', 'asc'));

    $first = systemQuery($source)->items()[0];

    expect($first->practice_topics)->toBe(1)
        ->and($first->latest_topic_id)->toBe(2)
        ->and((bool) $first->topics_exists)->toBeTrue();
});

it('computes optional aggregates only when the request includes them', function (): void {
    seedSubjects();
    $source = Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')->optional()]), Sort::make('name', 'asc'));

    $without = systemQuery($source)->items()[0];
    $with = systemQuery($source, ['include' => 'topics_count']);

    expect($without->getAttributes())->not->toHaveKey('topics_count')
        ->and($with->items()[0]->topics_count)->toBe(2)
        ->and($with->applied['include'])->toBe(['topics_count']);
});

it('sorts by an aggregate alias', function (): void {
    $subjects = seedSubjects();
    Topic::query()->create(['subject_id' => $subjects['economics']->id, 'name' => 'Economics Extra']);
    $source = Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')->optional()]), Sortables::make(['topics' => 'topics_count', 'name']));

    expect(pluck(systemQuery($source, ['sort' => '-topics,name']), 'name'))->toBe(['Economics', 'Algebra', 'Biology', 'Chemistry', 'Drama']);
});

it('adds aggregates to a record found by key', function (): void {
    $subjects = seedSubjects();
    $source = Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')->optional()]));

    $record = app(DataManager::class)->findOrFail($source, $subjects['algebra']->id, QueryContext::system(), ['include' => 'topics_count']);

    expect($record->topics_count)->toBe(2);
});

it('rejects invalid aggregate declarations', function (Closure $use, string $exception, string $message): void {
    expect($use)->toThrow($exception, $message);
})->with([
    'unknown relation' => [fn () => systemQuery(Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('missing')]))), InvalidSourceException::class, '"missing" is not a relationship'],
    'non-Eloquent source' => [fn () => systemQuery(Source::define(DatabaseTable::make('subjects'), Aggregates::make([RelationAggregate::count('topics')]))), InvalidSourceException::class, 'relationships need an Eloquent model source'],
    'duplicate alias' => [fn () => systemQuery(Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics'), RelationAggregate::count('topics')]))), InvalidSourceException::class, 'declared twice'],
    'cursor sort by alias' => [fn () => systemQuery(Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')]), Sortables::make(['topics' => 'topics_count']), Pagination::cursor(5))), InvalidSourceException::class, 'cursor pagination cannot sort by a relationship aggregate'],
    'unknown include' => [fn () => systemQuery(Source::define(Model::make(Subject::class), Aggregates::make([RelationAggregate::count('topics')->optional()])), ['include' => 'lessons_count']), InvalidQueryException::class, 'Allowed: topics_count'],
]);
