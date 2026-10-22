<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\Query;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\InstitutionDataScope;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Gcego\Data\Tests\Fixtures\Policies\SubjectPolicy;
use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->subjects = seedSubjects();
    $this->teacher = User::query()->create(['name' => 'Teacher']);
    $this->admin = User::query()->create(['name' => 'Admin', 'is_admin' => true]);
});

it('fails closed: guests and models without a policy are denied', function (): void {
    expect(fn () => app(DataManager::class)->query(Source::define(Model::make(Subject::class))))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DataManager::class)->query(Source::define(Model::make(Course::class)), context: QueryContext::for($this->admin)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DataManager::class)->query(Source::define(DatabaseTable::make('subjects')), context: QueryContext::for($this->admin)))->toThrow(AuthorizationException::class);
});

it('authorizes through the model policy before any query runs', function (): void {
    $this->actingAs($this->teacher);

    expect(app(DataManager::class)->query(Source::define(Model::make(Subject::class)))->pagination->total)->toBe(5);

    Gate::policy(Course::class, SubjectPolicy::class);
    $queries = queriesDuring(fn () => expect(fn () => app(DataManager::class)->query(Source::define(Model::make(Student::class))))->toThrow(AuthorizationException::class));

    expect($queries)->toBe([]);
});

it('authorizes each resolved record with the view ability', function (): void {
    $draft = $this->subjects['chemistry']->id;

    expect(fn () => app(DataManager::class)->find(Source::define(Model::make(Subject::class)), $draft, QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class)
        ->and(app(DataManager::class)->find(Source::define(Model::make(Subject::class)), $draft, QueryContext::for($this->admin))->found())->toBeTrue()
        ->and(app(DataManager::class)->find(Source::define(Model::make(Subject::class)), 999, QueryContext::for($this->teacher))->found())->toBeFalse();
});

it('checks the configured ability for query-builder and collection sources', function (): void {
    Gate::define('gcego-data.query', fn (User $user, string $operation, string $source): bool => $user->is_admin && $source === 'subjects');

    expect(app(DataManager::class)->query(Source::define(DatabaseTable::make('subjects')), context: QueryContext::for($this->admin))->pagination->total)->toBe(6)
        ->and(fn () => app(DataManager::class)->query(Source::define(DatabaseTable::make('subjects')), context: QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DataManager::class)->query(Source::define(Items::make([])), context: QueryContext::for($this->admin)))->toThrow(AuthorizationException::class);
});

it('rejects injection through every request parameter', function (array $input, string $exception): void {
    $source = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('code', [StringType::make(), Equal::make(), In::make()])]), Search::make(['name']), Sortables::make(['name']), Includables::make(['topics']));

    expect(fn () => app(DataManager::class)->query($source, $input, QueryContext::system()))->toThrow($exception);
})->with([
    'column as filter key' => [['filter' => ['institution_id' => '2']], InvalidFilterException::class],
    'sql in filter key' => [['filter' => ['code) or (1=1' => 'x']], InvalidFilterException::class],
    'sql operator' => [['filter' => ['code' => ['= 1 or 1 =' => 'x']]], InvalidFilterException::class],
    'sql in sort' => [['sort' => 'name desc, (select password from users)'], InvalidSortException::class],
    'unlisted sort column' => [['sort' => 'institution_id'], InvalidSortException::class],
    'arbitrary relation' => [['include' => 'topics.subject'], InvalidQueryException::class],
    'relation to users' => [['include' => 'users'], InvalidQueryException::class],
]);

it('keeps search input inert', function (): void {
    $source = Source::define(Model::make(Subject::class), Search::make(['name']));

    expect(app(DataManager::class)->query($source, ['search' => "' OR 1=1 --"], QueryContext::system())->isEmpty())->toBeTrue()
        ->and(app(DataManager::class)->query($source, ['search' => '%'], QueryContext::system())->isEmpty())->toBeTrue();
});

it('isolates constraints so an or-where cannot widen an earlier scope', function (): void {
    app(DataManager::class)->scope(new InstitutionDataScope(1), Subject::class);
    $leaky = Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn ($query) => $query->orWhere('is_published', true))]));

    $result = app(DataManager::class)->query($leaky, context: QueryContext::system()->constrain(fn ($query) => $query->orWhere('level', 1)));
    $sql = app(DataManager::class)->explain($leaky)['sql'];

    expect(pluck($result, 'institution_id'))->each->toBe(1)
        ->and(pluck($result, 'name'))->toBe(['Algebra'])
        ->and($sql)->toContain('where ("is_published" = ?) and "subjects"."institution_id" = ?');
});

it('rejects constraints that swap the builder for a fresh one', function (): void {
    $swap = Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn () => Subject::query()->withoutGlobalScopes())]));

    systemQuery($swap);
})->throws(InvalidSourceException::class, 'must modify and return the builder it receives');

it('applies registered scopes to sources of matching models only', function (): void {
    app(DataManager::class)->scope(fn ($query) => $query->where('level', 1), Subject::class);
    Course::query()->create(['code' => 'A', 'title' => 'A']);

    expect(systemQuery(Source::define(Model::make(Subject::class)))->pagination->total)->toBe(1)
        ->and(systemQuery(Source::define(Model::make(Course::class)))->pagination->total)->toBe(1);
});

it('caps excessive page sizes', function (): void {
    expect(systemQuery(Source::define(Model::make(Subject::class)), ['per_page' => '100000000'])->pagination->perPage)->toBe(100);
});

it('never exposes hidden attributes', function (): void {
    app(Tenancy::class)->institution = 1;
    Student::query()->create(['name' => 'Ada', 'national_id' => 'NAT-SECRET', 'institution_id' => 1]);
    $plain = (string) json_encode(systemQuery(Source::define(Model::make(Student::class))));
    $only = (string) json_encode(systemQuery(Source::define(Model::make(Student::class), Transform::only(['name', 'national_id']))));

    expect($plain)->not->toContain('NAT-SECRET')
        ->and($only)->not->toContain('NAT-SECRET')
        ->and($only)->toContain('"data":[{"name":"Ada"}]');
});

it('does not expose binding values in diagnostics outside debug mode', function (): void {
    config()->set('app.debug', false);
    $explained = app(DataManager::class)->explain(Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('code', [StringType::make(), Equal::make(), In::make()])])), ['filter' => ['code' => 'PRIVATE-VALUE']], includeBindings: true);

    expect($explained)->not->toHaveKey('values')
        ->and((string) json_encode($explained))->not->toContain('PRIVATE-VALUE')
        ->and($explained['bindings'])->toBeGreaterThan(0);
});
