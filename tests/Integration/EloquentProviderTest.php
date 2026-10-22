<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Property;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Relationships\Includable;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Database\Eloquent\Builder;

beforeEach(fn () => seedSubjects());

it('lists models with framework defaults', function (): void {
    $result = systemQuery(new Source(Subject::class));

    expect(pluck($result, 'name'))->toBe(['Algebra', 'Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and($result->items()[0])->toBeInstanceOf(Subject::class)
        ->and($result->pagination->total)->toBe(5)
        ->and($result->pagination->perPage)->toBe(25)
        ->and($result->source)->toBe('subjects')
        ->and($result->provider)->toBe('eloquent');
});

it('runs the declarative level-3 example', function (): void {
    $result = systemQuery(new Source(Subject::class, new Filter('is_published', true), new Sort('name', 'desc'), new Paginate(2)));

    expect(pluck($result, 'name'))->toBe(['Economics', 'Drama'])
        ->and($result->pagination->total)->toBe(4)
        ->and($result->pagination->lastPage)->toBe(2);
});

it('runs trusted query callbacks', function (): void {
    $result = systemQuery(new Source(Subject::class, new Query(fn (Builder $query) => $query->where('level', '>=', 2)->with('topics'))));

    expect(pluck($result, 'name'))->toBe(['Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and($result->items()[0]->relationLoaded('topics'))->toBeTrue();
});

it('excludes soft-deleted records unless the developer opts in', function (): void {
    expect(pluck(systemQuery(new Source(Subject::class)), 'name'))->not->toContain('French')
        ->and(pluck(systemQuery(new Source(Subject::class, new Query(fn (Builder $query) => $query->withTrashed()))), 'name'))->toContain('French')
        ->and(fn () => systemQuery(new Source(Subject::class, new Filterable('deleted_at')), ['filter' => ['deleted_at' => ['not_null' => '1']]]))->toThrow(InvalidFilterException::class);

    // Filtering on deleted_at cannot reveal trashed rows: the SoftDeletingScope is still AND-ed.
    expect(systemQuery(new Source(Subject::class, new Filterable('deleted_at', FilterOperator::NotNull)), ['filter' => ['deleted_at' => ['not_null' => '1']]])->isEmpty())->toBeTrue();
});

it('preserves global scopes, including fail-closed tenant scopes', function (): void {
    Student::query()->create(['name' => 'Ada', 'national_id' => 'N-1', 'institution_id' => 1]);
    Student::query()->create(['name' => 'Bo', 'national_id' => 'N-2', 'institution_id' => 2]);
    $source = new Source(Student::class, new Sort('name'));

    expect(systemQuery($source)->isEmpty())->toBeTrue();

    app(Tenancy::class)->institution = 2;

    expect(pluck(systemQuery($source), 'name'))->toBe(['Bo']);
});

it('respects the configured connection', function (): void {
    Subject::on('archive')->create(['name' => 'Archived Latin', 'institution_id' => 1]);

    $queries = queriesDuring(function () use (&$result): void {
        $result = systemQuery(new Source(Subject::class, new Property(Source::Connection, 'archive')));
    });

    expect(pluck($result, 'name'))->toBe(['Archived Latin'])
        ->and($queries)->each->toStartWith('archive: ');
});

it('filters and searches through relationships with whereHas', function (): void {
    $source = new Source(Subject::class, new Filterable('topic', column: 'topics.name'), new Search('name', 'topics.lessons.title'));

    expect(pluck(systemQuery($source, ['filter' => ['topic' => 'Drama Practice']]), 'name'))->toBe(['Drama'])
        ->and(pluck(systemQuery($source, ['search' => 'Biology Introduction lesson']), 'name'))->toBe(['Biology']);
});

it('eager loads declared and requested relations without N+1 queries', function (): void {
    $source = new Source(Subject::class, new With('topics'), new Includable('topics.lessons'));

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
    $result = systemQuery(new Source(Subject::class, new With(['topics' => fn ($query) => $query->where('name', 'like', '%Practice')])));

    expect($result->items()[0]->topics->pluck('name')->all())->toBe(['Algebra Practice']);
});

it('rejects unknown relationships when the source is first used', function (string $path): void {
    expect(fn () => systemQuery(new Source(Subject::class, new With($path))))->toThrow(InvalidSourceException::class, 'is not a relationship of');
})->with(['missing', 'notARelation', 'topics.missing', 'delete']);

it('supports custom primary keys for ordering', function (): void {
    foreach (['PHY' => 'Physics', 'ART' => 'Art', 'MUS' => 'Music'] as $code => $title) {
        Course::query()->create(['code' => $code, 'title' => $title]);
    }

    $sql = app(DataManager::class)->explain(new Source(Course::class))['sql'];

    expect(pluck(systemQuery(new Source(Course::class)), 'code'))->toBe(['ART', 'MUS', 'PHY'])
        ->and($sql)->toContain('order by "courses"."code" asc');
});

it('rejects selecting columns on Eloquent sources', function (): void {
    systemQuery(new Source(Subject::class, new Property(Source::Columns, ['id'])));
})->throws(InvalidSourceException::class, 'Transform::only()');

it('serializes models through their own toArray()', function (): void {
    Student::query()->create(['name' => 'Ada', 'national_id' => 'N-SECRET', 'institution_id' => 1]);
    app(Tenancy::class)->institution = 1;

    $json = (string) json_encode(app(DataManager::class)->query(new Source(Student::class), context: QueryContext::system()));

    expect($json)->toContain('"name":"Ada"')
        ->and($json)->not->toContain('N-SECRET')
        ->and($json)->toContain('"pagination":{"mode":"pages"');
});
