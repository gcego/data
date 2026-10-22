<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\InstitutionDataScope;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Enrolment;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Support\Str;

function findRecord(Source $source, string|int $key, ?QueryContext $context = null): mixed
{
    return app(DataManager::class)->find($source, $key, $context ?? QueryContext::system())->record();
}

it('resolves integer keys, given as integers or strings', function (): void {
    $subjects = seedSubjects();

    expect(findRecord(Source::define(Model::make(Subject::class)), $subjects['biology']->id)?->name)->toBe('Biology')
        ->and(findRecord(Source::define(Model::make(Subject::class)), (string) $subjects['biology']->id)?->name)->toBe('Biology');
});

it('resolves UUID, ULID and custom string keys', function (): void {
    app(Tenancy::class)->institution = 1;
    $student = Student::query()->create(['name' => 'Ada', 'national_id' => 'N-1', 'institution_id' => 1]);
    $enrolment = Enrolment::query()->create(['status' => 'active']);
    Course::query()->create(['code' => 'PHY-101', 'title' => 'Physics']);

    expect(Str::isUuid($student->id))->toBeTrue()
        ->and(findRecord(Source::define(Model::make(Student::class)), $student->id)?->name)->toBe('Ada')
        ->and(findRecord(Source::define(Model::make(Enrolment::class)), $enrolment->id)?->status)->toBe('active')
        ->and(findRecord(Source::define(Model::make(Course::class)), 'PHY-101')?->title)->toBe('Physics');
});

it('treats malformed keys as not found without querying', function (Source $source, string $key): void {
    $queries = queriesDuring(function () use ($source, $key, &$result): void {
        $result = app(DataManager::class)->find($source, $key, QueryContext::system());
    });

    expect($result->found())->toBeFalse()
        ->and($queries)->toBe([]);
})->with([
    'non-numeric integer key' => [Source::define(Model::make(Subject::class)), '1 OR 1=1'],
    'invalid uuid' => [Source::define(Model::make(Student::class)), 'not-a-uuid'],
    'invalid ulid' => [Source::define(Model::make(Enrolment::class)), '01ARZ3NDEKTSV4RRFFQ69G5FA!'],
    'control characters' => [Source::define(Model::make(Course::class)), "PHY\0"],
]);

it('keeps lookups inside every mandatory constraint', function (): void {
    $subjects = seedSubjects();
    $draft = $subjects['chemistry']->id;

    expect(findRecord(Source::define(Model::make(Subject::class), Constraints::make([Filter::make('is_published', true)])), $draft))->toBeNull()
        ->and(findRecord(Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn ($query) => $query->where('level', 1))])), $draft))->toBeNull()
        ->and(findRecord(Source::define(Model::make(Subject::class)), $draft))->not->toBeNull();
});

it('never resolves soft-deleted records by default', function (): void {
    $subjects = seedSubjects();

    expect(findRecord(Source::define(Model::make(Subject::class)), $subjects['french']->id))->toBeNull()
        ->and(findRecord(Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn ($query) => $query->withTrashed())])), $subjects['french']->id)?->name)->toBe('French');
});

it('prevents cross-tenant lookups through global, registered and context scopes', function (): void {
    $subjects = seedSubjects();
    app(Tenancy::class)->institution = 1;
    $other = Student::query()->create(['name' => 'Other school', 'national_id' => 'X', 'institution_id' => 2]);

    // Global scope (Eloquent): the id is known, the record is still invisible.
    expect(findRecord(Source::define(Model::make(Student::class)), $other->id))->toBeNull();

    // Request-scoped scope from QueryContext.
    $context = QueryContext::system()->constrain(new InstitutionDataScope(1));
    expect(findRecord(Source::define(Model::make(Subject::class)), $subjects['drama']->id, $context))->toBeNull()
        ->and(findRecord(Source::define(Model::make(Subject::class)), $subjects['algebra']->id, $context))->not->toBeNull();

    // Application-wide scope registered for the model.
    app(DataManager::class)->scope(new InstitutionDataScope(1), Subject::class);
    expect(findRecord(Source::define(Model::make(Subject::class)), $subjects['economics']->id))->toBeNull();
});

it('fails with a 404 exception that does not reveal the key', function (): void {
    seedSubjects();

    try {
        app(DataManager::class)->findOrFail(Source::define(Model::make(Subject::class)), 999, QueryContext::system());
        $this->fail('Expected RecordNotFoundException.');
    } catch (RecordNotFoundException $exception) {
        expect($exception->getStatusCode())->toBe(404)
            ->and($exception->getMessage())->toBe('The requested record was not found.')
            ->and($exception->context())->toBe(['source' => 'subjects']);
    }
});

it('loads declared and allowlisted relations for one record', function (): void {
    $subjects = seedSubjects();
    $source = Source::define(Model::make(Subject::class), Relations::make(['topics']), Includables::make(['topics.lessons']));

    $record = app(DataManager::class)->find($source, $subjects['algebra']->id, QueryContext::system(), ['include' => 'topics.lessons'])->record();

    expect($record->relationLoaded('topics'))->toBeTrue()
        ->and($record->topics->first()->relationLoaded('lessons'))->toBeTrue()
        ->and(fn () => app(DataManager::class)->find($source, 1, QueryContext::system(), ['include' => 'secrets']))->toThrow(InvalidQueryException::class);
});

it('applies transforms to resolved records', function (): void {
    $subjects = seedSubjects();

    expect(findRecord(Source::define(Model::make(Subject::class), Transform::only(['name', 'code'])), $subjects['algebra']->id))->toBe(['name' => 'Algebra', 'code' => 'MTH101']);
});
