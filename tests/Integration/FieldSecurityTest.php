<?php

declare(strict_types=1);

use Gcego\Data\Aggregation\AggregateFunction;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\FieldSecurity;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Security\FieldAccess;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->subjects = seedSubjects();
    $this->teacher = User::query()->create(['name' => 'Teacher']);
    $this->admin = User::query()->create(['name' => 'Admin', 'is_admin' => true]);
    Gate::define('viewCodes', fn (User $user): bool => $user->is_admin === true);
});

function codedSubjects(SourceConfiguration ...$definitions): Source
{
    return Source::define(Model::make(Subject::class), FieldSecurity::make([FieldAccess::ability('code', read: 'viewCodes')]), Sort::make('name', 'asc'), ...$definitions);
}

it('removes fields a user may not read from listings and records', function (): void {
    $teacherPage = Data::query(codedSubjects(), context: QueryContext::for($this->teacher));
    $adminPage = Data::query(codedSubjects(), context: QueryContext::for($this->admin));
    $record = Data::findOrFail(codedSubjects(), $this->subjects['algebra']->id, QueryContext::for($this->teacher));

    expect($teacherPage->toArray()['data'][0])->not->toHaveKey('code')
        ->and($teacherPage->items()[0]->code)->toBeNull()
        ->and($adminPage->toArray()['data'][0]['code'])->toBe('MTH101')
        ->and($record->toArray())->not->toHaveKey('code');
});

it('never exposes hidden fields, even to transforms that list them', function (): void {
    $source = Source::define(Model::make(Subject::class), FieldSecurity::make([FieldAccess::hidden('code')]), Transform::only(['name', 'code']));

    expect(Data::findOrFail($source, $this->subjects['algebra']->id, QueryContext::system()))->toBe(['name' => 'Algebra']);
});

it('rejects filtering and sorting by unreadable fields with 400', function (): void {
    $source = codedSubjects(Filterables::make([Filterable::make('code', [StringType::make(), Equal::make(), In::make()])]), Sortables::make(['code']));

    expect(fn () => Data::query($source, ['filter' => ['code' => 'MTH101']], QueryContext::for($this->teacher)))->toThrow(InvalidFilterException::class, 'a field you may not read')
        ->and(fn () => Data::query($source, ['sort' => 'code'], QueryContext::for($this->teacher)))->toThrow(InvalidSortException::class, 'a field you may not read')
        ->and(Data::query($source, ['filter' => ['code' => 'MTH101']], QueryContext::for($this->admin))->count())->toBe(1);
});

it('does not search unreadable fields', function (): void {
    $source = codedSubjects(Search::make(['name', 'code']));

    expect(Data::query($source, ['search' => 'MTH'], QueryContext::for($this->teacher))->count())->toBe(0)
        ->and(Data::query($source, ['search' => 'MTH'], QueryContext::for($this->admin))->count())->toBe(1);
});

it('forbids aggregating unreadable fields', function (): void {
    expect(fn () => Data::aggregate(codedSubjects(), new Aggregation(AggregateFunction::Count, groupBy: 'code'), context: QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class, 'aggregate the "code" field');
});

it('decides per record with closure rules', function (): void {
    $source = Source::define(Model::make(Subject::class), FieldSecurity::make([FieldAccess::make('code', read: fn (?User $user, ?Subject $subject): bool => $subject?->level === 1)]));

    expect(Data::findOrFail($source, $this->subjects['algebra']->id, QueryContext::for($this->admin))->code)->toBe('MTH101')
        ->and(Data::findOrFail($source, $this->subjects['biology']->id, QueryContext::for($this->admin))->code)->toBeNull();
});

it('forbids writing read-only fields with 403 and changes nothing', function (): void {
    $source = Source::define(Model::make(Subject::class), FieldSecurity::make([FieldAccess::readOnly('code')]), Writable::make(['name' => 'string', 'code' => 'string']));

    expect(fn () => Data::update($source, $this->subjects['algebra']->id, ['code' => 'HACK'], QueryContext::for($this->admin)))->toThrow(AuthorizationException::class, 'You may not change the code field');
    $this->assertDatabaseHas('subjects', ['id' => $this->subjects['algebra']->id, 'code' => 'MTH101']);
});

it('removes protected fields of included relations', function (): void {
    $source = Source::define(Model::make(Subject::class), Includables::make(['topics']), FieldSecurity::make([FieldAccess::hidden('topics.name')]));

    $record = Data::findOrFail($source, $this->subjects['algebra']->id, QueryContext::for($this->admin), ['include' => 'topics']);

    expect($record->toArray()['topics'][0])->not->toHaveKey('name')
        ->and($record->toArray()['topics'][0])->toHaveKey('subject_id');
});
