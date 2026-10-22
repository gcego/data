<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Columns;
use Gcego\Data\Definitions\Connection;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\IsNotNull;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Database\Eloquent\Builder;

beforeEach(fn () => seedSubjects());

it('lists models with framework defaults', function (): void {
    $result = systemQuery(Source::define(Model::make(Subject::class)));

    expect(pluck($result, 'name'))->toBe(['Algebra', 'Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and($result->items()[0])->toBeInstanceOf(Subject::class)
        ->and($result->pagination->total)->toBe(5)
        ->and($result->pagination->perPage)->toBe(25)
        ->and($result->source)->toBe('subjects')
        ->and($result->provider)->toBe('eloquent');
});

it('runs the declarative level-3 example', function (): void {
    $result = systemQuery(Source::define(Model::make(Subject::class), Constraints::make([Filter::make('is_published', true)]), Sort::make('name', 'desc'), Pagination::make(2)));

    expect(pluck($result, 'name'))->toBe(['Economics', 'Drama'])
        ->and($result->pagination->total)->toBe(4)
        ->and($result->pagination->lastPage)->toBe(2);
});

it('runs trusted query callbacks', function (): void {
    $result = systemQuery(Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn (Builder $query) => $query->where('level', '>=', 2)->with('topics'))])));

    expect(pluck($result, 'name'))->toBe(['Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and($result->items()[0]->relationLoaded('topics'))->toBeTrue();
});

it('excludes soft-deleted records unless the developer opts in', function (): void {
    expect(pluck(systemQuery(Source::define(Model::make(Subject::class))), 'name'))->not->toContain('French')
        ->and(pluck(systemQuery(Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn (Builder $query) => $query->withTrashed())]))), 'name'))->toContain('French')
        ->and(fn () => systemQuery(Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('deleted_at', [StringType::make(), Equal::make(), In::make()])])), ['filter' => ['deleted_at' => ['not_null' => '1']]]))->toThrow(InvalidFilterException::class);

    // Filtering on deleted_at cannot reveal trashed rows: the SoftDeletingScope is still AND-ed.
    expect(systemQuery(Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('deleted_at', [StringType::make(), IsNotNull::make()])])), ['filter' => ['deleted_at' => ['not_null' => '1']]])->isEmpty())->toBeTrue();
});

it('preserves global scopes, including fail-closed tenant scopes', function (): void {
    Student::query()->create(['name' => 'Ada', 'national_id' => 'N-1', 'institution_id' => 1]);
    Student::query()->create(['name' => 'Bo', 'national_id' => 'N-2', 'institution_id' => 2]);
    $source = Source::define(Model::make(Student::class), Sort::make('name', 'asc'));

    expect(systemQuery($source)->isEmpty())->toBeTrue();

    app(Tenancy::class)->institution = 2;

    expect(pluck(systemQuery($source), 'name'))->toBe(['Bo']);
});

it('respects the configured connection', function (): void {
    Subject::on('archive')->create(['name' => 'Archived Latin', 'institution_id' => 1]);

    $queries = queriesDuring(function () use (&$result): void {
        $result = systemQuery(Source::define(Model::make(Subject::class), Connection::make('archive')));
    });

    expect(pluck($result, 'name'))->toBe(['Archived Latin'])
        ->and($queries)->each->toStartWith('archive: ');
});

it('filters and searches through relationships with whereHas', function (): void {
    $source = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('topic', [StringType::make(), Equal::make(), In::make(), Column::make('topics.name')])]), Search::make(['name', 'topics.lessons.title']));

    expect(pluck(systemQuery($source, ['filter' => ['topic' => 'Drama Practice']]), 'name'))->toBe(['Drama'])
        ->and(pluck(systemQuery($source, ['search' => 'Biology Introduction lesson']), 'name'))->toBe(['Biology']);
});

it('eager loads declared and requested relations without N+1 queries', function (): void {
    $source = Source::define(Model::make(Subject::class), Relations::make(['topics']), Includables::make(['topics.lessons']));

    $queries = queriesDuring(function () use ($source, &$result): void {
        $result = systemQuery($source, ['include' => 'topics.lessons']);

        foreach ($result as $subject) {
            foreach ($subject->topics as $topic) {
                $topic->lessons->count();
            }
        }
    });

    // count, page, topics, lessons: independent of the number of subjects.
    expect($queries)->toHaveCount(4)
        ->and($result->items()[0]->topics->first()->relationLoaded('lessons'))->toBeTrue()
        ->and($result->applied['include'])->toBe(['topics.lessons']);
});

it('applies relation constraints from trusted callbacks', function (): void {
    $result = systemQuery(Source::define(Model::make(Subject::class), Relations::make(['topics' => fn ($query) => $query->where('name', 'like', '%Practice')])));

    expect($result->items()[0]->topics->pluck('name')->all())->toBe(['Algebra Practice']);
});

it('rejects unknown relationships when the source is first used', function (string $path): void {
    expect(fn () => systemQuery(Source::define(Model::make(Subject::class), Relations::make([$path]))))->toThrow(InvalidSourceException::class, 'is not a relationship of');
})->with(['missing', 'notARelation', 'topics.missing', 'delete']);

it('supports custom primary keys for ordering', function (): void {
    foreach (['PHY' => 'Physics', 'ART' => 'Art', 'MUS' => 'Music'] as $code => $title) {
        Course::query()->create(['code' => $code, 'title' => $title]);
    }

    $sql = app(DataManager::class)->explain(Source::define(Model::make(Course::class)))['sql'];

    expect(pluck(systemQuery(Source::define(Model::make(Course::class))), 'code'))->toBe(['ART', 'MUS', 'PHY'])
        ->and($sql)->toContain('order by "courses"."code" asc');
});

it('rejects selecting columns on Eloquent sources', function (): void {
    systemQuery(Source::define(Model::make(Subject::class), Columns::make(['id'])));
})->throws(InvalidSourceException::class, 'Columns and Key are for table');

it('serializes models through their own toArray()', function (): void {
    Student::query()->create(['name' => 'Ada', 'national_id' => 'N-SECRET', 'institution_id' => 1]);
    app(Tenancy::class)->institution = 1;

    $json = (string) json_encode(app(DataManager::class)->query(Source::define(Model::make(Student::class)), context: QueryContext::system()));

    expect($json)->toContain('"name":"Ada"')
        ->and($json)->not->toContain('N-SECRET')
        ->and($json)->toContain('"pagination":{"mode":"pages"');
});
