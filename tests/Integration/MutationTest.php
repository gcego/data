<?php

declare(strict_types=1);

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\BeforeSave;
use Gcego\Data\Definitions\BuilderFactory;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Key;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Events\RecordCreated;
use Gcego\Data\Events\RecordDeleted;
use Gcego\Data\Events\RecordRestored;
use Gcego\Data\Events\RecordUpdated;
use Gcego\Data\Exceptions\MutationException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->admin = User::query()->create(['name' => 'Admin', 'is_admin' => true]);
    $this->teacher = User::query()->create(['name' => 'Teacher']);
});

function writableSubjects(SourceConfiguration ...$definitions): Source
{
    return Source::define(Model::make(Subject::class), Writable::make([
        'name' => ['required', 'string', 'max:40'],
        'code' => fn (?Subject $subject): array => ['nullable', 'string', Rule::unique('subjects', 'code')->ignore($subject?->id)],
        'level' => 'integer|between:1,6',
        'is_published' => 'boolean',
    ], [DataOperation::Create, DataOperation::Update, DataOperation::Delete, DataOperation::Restore, DataOperation::ForceDelete]), ...$definitions);
}

it('creates a validated record and dispatches RecordCreated after commit', function (): void {
    Event::fake([RecordCreated::class]);

    $result = Data::create(writableSubjects(), ['name' => 'Physics', 'code' => 'PHY101', 'level' => 2], QueryContext::for($this->admin));

    expect($result->operation)->toBe(DataOperation::Create)
        ->and($result->record->name)->toBe('Physics')
        ->and($result->changes)->toBe(['name' => 'Physics', 'code' => 'PHY101', 'level' => 2]);
    $this->assertDatabaseHas('subjects', ['id' => $result->key, 'name' => 'Physics', 'level' => 2]);
    Event::assertDispatched(RecordCreated::class, fn (RecordCreated $event): bool => $event->key === $result->key && $event->user?->is($this->admin) === true);
});

it('rejects fields outside the allowlist with 422 and writes nothing', function (): void {
    expect(fn () => Data::create(writableSubjects(), ['name' => 'Physics', 'institution_id' => 99], QueryContext::for($this->admin)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['institution_id' => ['The institution_id field is not writable.']]));
    $this->assertDatabaseCount('subjects', 0);
});

it('rejects invalid values with the validation messages', function (): void {
    Subject::query()->create(['name' => 'Algebra', 'code' => 'MTH101']);

    expect(fn () => Data::create(writableSubjects(), ['name' => '', 'code' => 'MTH101', 'level' => 9], QueryContext::for($this->admin)))
        ->toThrow(fn (ValidationException $exception) => expect(array_keys($exception->errors()))->toBe(['name', 'code', 'level']));
    $this->assertDatabaseCount('subjects', 1);
});

it('forbids writes the policy denies with 403 and writes nothing', function (): void {
    $algebra = Subject::query()->create(['name' => 'Algebra', 'is_published' => true]);

    expect(fn () => Data::create(writableSubjects(), ['name' => 'Physics'], QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class)
        ->and(fn () => Data::update(writableSubjects(), $algebra->id, ['name' => 'Hacked'], QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class)
        ->and(fn () => Data::delete(writableSubjects(), $algebra->id, QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('subjects', 1);
    $this->assertDatabaseHas('subjects', ['id' => $algebra->id, 'name' => 'Algebra', 'deleted_at' => null]);
});

it('updates only the given fields and reports the changes', function (): void {
    Event::fake([RecordUpdated::class]);
    $algebra = Subject::query()->create(['name' => 'Algebra', 'code' => 'MTH101', 'level' => 1]);

    $result = Data::update(writableSubjects(), $algebra->id, ['level' => 2, 'code' => 'MTH101'], QueryContext::for($this->admin));

    expect($result->changes)->toHaveKey('level', 2)
        ->and($result->changes)->not->toHaveKey('code')
        ->and($result->original)->toMatchArray(['level' => 1]);
    $this->assertDatabaseHas('subjects', ['id' => $algebra->id, 'name' => 'Algebra', 'level' => 2]);
    Event::assertDispatched(RecordUpdated::class, fn (RecordUpdated $event): bool => $event->changes['level'] === 2);
});

it('cannot update or delete a record outside the source scope', function (): void {
    $draft = Subject::query()->create(['name' => 'Draft', 'is_published' => false]);
    $published = writableSubjects(Constraints::make([Filter::make('is_published', true)]));

    expect(fn () => Data::update($published, $draft->id, ['name' => 'X'], QueryContext::for($this->admin)))->toThrow(RecordNotFoundException::class)
        ->and(fn () => Data::delete($published, $draft->id, QueryContext::for($this->admin)))->toThrow(RecordNotFoundException::class);
    $this->assertDatabaseHas('subjects', ['id' => $draft->id, 'name' => 'Draft', 'deleted_at' => null]);
});

it('rolls back a write that would move the record out of scope', function (): void {
    Event::fake([RecordUpdated::class]);
    $algebra = Subject::query()->create(['name' => 'Algebra', 'is_published' => true]);

    expect(fn () => Data::update(writableSubjects(Constraints::make([Filter::make('is_published', true)])), $algebra->id, ['is_published' => false], QueryContext::for($this->admin)))->toThrow(MutationException::class, 'outside the source');
    $this->assertDatabaseHas('subjects', ['id' => $algebra->id, 'is_published' => true]);
    Event::assertNotDispatched(RecordUpdated::class);
});

it('soft deletes, restores and force deletes', function (): void {
    Event::fake([RecordDeleted::class, RecordRestored::class]);
    $algebra = Subject::query()->create(['name' => 'Algebra']);

    Data::delete(writableSubjects(), $algebra->id, QueryContext::for($this->admin));
    $this->assertSoftDeleted('subjects', ['id' => $algebra->id]);

    $restored = Data::restore(writableSubjects(), $algebra->id, QueryContext::for($this->admin));
    $this->assertNotSoftDeleted('subjects', ['id' => $algebra->id]);

    Data::forceDelete(writableSubjects(), $algebra->id, QueryContext::for($this->admin));
    $this->assertDatabaseMissing('subjects', ['id' => $algebra->id]);

    expect($restored->record->name)->toBe('Algebra');
    Event::assertDispatchedTimes(RecordDeleted::class, 2);
    Event::assertDispatched(RecordRestored::class);
});

it('prepares attributes with BeforeSave callbacks', function (): void {
    $source = writableSubjects(BeforeSave::make(fn (array $attributes): array => [...$attributes, 'code' => strtoupper(substr((string) ($attributes['name'] ?? ''), 0, 3)).'100']));

    $result = Data::create($source, ['name' => 'Physics'], QueryContext::for($this->admin));

    expect($result->record->code)->toBe('PHY100');
});

it('presents the written record like a read', function (): void {
    $result = Data::create(writableSubjects(Transform::only(['name'])), ['name' => 'Physics'], QueryContext::for($this->admin));

    expect($result->record)->toBe(['name' => 'Physics']);
});

it('creates many records atomically', function (): void {
    expect(fn () => Data::createMany(writableSubjects(), [['name' => 'Physics'], ['name' => '']], QueryContext::for($this->admin)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('subjects', 0);

    $results = Data::createMany(writableSubjects(), [['name' => 'Physics'], ['name' => 'Latin']], QueryContext::for($this->admin));

    expect($results)->toHaveCount(2);
    $this->assertDatabaseCount('subjects', 2);
});

it('updates and deletes many records, each authorized and scoped', function (): void {
    $algebra = Subject::query()->create(['name' => 'Algebra']);
    $biology = Subject::query()->create(['name' => 'Biology']);

    Data::updateMany(writableSubjects(), [$algebra->id, $biology->id], ['level' => 3], QueryContext::for($this->admin));
    Data::deleteMany(writableSubjects(), [$algebra->id], QueryContext::for($this->admin));

    $this->assertDatabaseHas('subjects', ['id' => $biology->id, 'level' => 3]);
    $this->assertSoftDeleted('subjects', ['id' => $algebra->id]);
});

it('refuses writes to read-only sources and undeclared operations', function (): void {
    $algebra = Subject::query()->create(['name' => 'Algebra']);

    expect(fn () => Data::create(Source::define(Model::make(Subject::class)), ['name' => 'X'], QueryContext::system()))->toThrow(MutationException::class, 'is read-only')
        ->and(fn () => Data::delete(Source::define(Model::make(Subject::class), Writable::make(['name'], [DataOperation::Update])), $algebra->id, QueryContext::system()))->toThrow(MutationException::class, 'does not allow delete');
});

it('writes query-builder table sources and refuses builder factories', function (): void {
    Gate::define('gcego-data.query', fn (?User $user): bool => true);
    $table = Source::define(DatabaseTable::make('courses'), Key::make('code'), Writable::make(['code' => 'required|string', 'title' => 'required|string']));

    $created = Data::create($table, ['code' => 'C1', 'title' => 'Anatomy'], QueryContext::system());
    $updated = Data::update($table, 'C1', ['title' => 'Human Anatomy'], QueryContext::system());

    expect($created->record)->toMatchArray(['code' => 'C1', 'title' => 'Anatomy'])
        ->and($updated->changes)->toBe(['title' => 'Human Anatomy'])
        ->and(fn () => Data::create(Source::define(BuilderFactory::make(fn () => DB::table('courses')), Writable::make(['title'])), ['title' => 'X'], QueryContext::system()))->toThrow(MutationException::class, 'cannot be written');
    $this->assertDatabaseHas('courses', ['code' => 'C1', 'title' => 'Human Anatomy']);
});
