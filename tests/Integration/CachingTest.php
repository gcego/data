<?php

declare(strict_types=1);

use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Caching\CacheIsolation;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Contracts\TenantResolver;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\FieldSecurity;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\TenantScoped;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Security\FieldAccess;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\CurrentTenant;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\Topic;
use Gcego\Data\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->subjects = seedSubjects();
    $this->admin = User::query()->create(['name' => 'Admin', 'is_admin' => true]);
    $this->teacher = User::query()->create(['name' => 'Teacher']);
});

function cachedSubjects(CacheIsolation $isolation = CacheIsolation::User, SourceConfiguration ...$definitions): Source
{
    return Source::define(Name::make('cached-subjects'), Model::make(Subject::class), Sort::make('name', 'asc'), Cached::make(ttl: 60, isolation: $isolation), Writable::make(['name' => 'string', 'level' => 'integer']), ...$definitions);
}

it('answers repeated queries from the cache without touching the database', function (): void {
    $first = Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    $queries = queriesDuring(function () use (&$second): void {
        $second = Data::query(cachedSubjects(), context: QueryContext::for($this->admin));
    });

    expect($first->cache)->toBe('miss')
        ->and($second->cache)->toBe('hit')
        ->and($queries)->toBe([])
        ->and(pluck($second, 'name'))->toBe(pluck($first, 'name'))
        ->and($second->pagination->total)->toBe(5);
});

it('keeps separate entries per user by default', function (): void {
    Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    expect(Data::query(cachedSubjects(), context: QueryContext::for($this->teacher))->cache)->toBe('miss');
});

it('shares entries within a tenant and isolates tenants', function (): void {
    $this->app->instance(TenantResolver::class, $tenant = new CurrentTenant);
    $source = cachedSubjects(CacheIsolation::Tenant, TenantScoped::make('institution_id'));
    $tenant->tenant = 1;
    Data::query($source, context: QueryContext::for($this->admin));

    $sameTenant = Data::query($source, context: QueryContext::for($this->teacher));
    $tenant->tenant = 2;
    $otherTenant = Data::query($source, context: QueryContext::for($this->teacher));

    expect($sameTenant->cache)->toBe('hit')
        ->and($otherTenant->cache)->toBe('miss')
        ->and(pluck($otherTenant, 'name'))->toBe(['Drama', 'Economics']);
});

it('invalidates after a write through gcego/data', function (): void {
    Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    Data::update(cachedSubjects(), $this->subjects['algebra']->id, ['name' => 'Algebra II'], QueryContext::for($this->admin));
    $after = Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    expect($after->cache)->toBe('miss')
        ->and(pluck($after, 'name'))->toContain('Algebra II');
});

it('invalidates after model writes made outside gcego/data, including related models', function (): void {
    $source = cachedSubjects(CacheIsolation::User, Relations::make(['topics']));
    Data::query($source, context: QueryContext::for($this->admin));

    $this->subjects['biology']->update(['name' => 'Life Sciences']);
    $afterSubject = Data::query($source, context: QueryContext::for($this->admin));
    Topic::query()->first()?->update(['name' => 'Renamed']);
    $afterTopic = Data::query($source, context: QueryContext::for($this->admin));

    expect($afterSubject->cache)->toBe('miss')
        ->and(pluck($afterSubject, 'name'))->toContain('Life Sciences')
        ->and($afterTopic->cache)->toBe('miss');
});

it('invalidates on demand', function (): void {
    Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    Data::invalidate(cachedSubjects());

    expect(Data::query(cachedSubjects(), context: QueryContext::for($this->admin))->cache)->toBe('miss');
});

it('only caches context constraints that name a cache scope', function (): void {
    $constrained = QueryContext::for($this->admin)->constrain(fn ($query) => $query->where('level', 1));

    $unscoped = Data::query(cachedSubjects(), context: $constrained);
    Data::query(cachedSubjects(), context: $constrained->cacheScope('level:1'));
    $scoped = Data::query(cachedSubjects(), context: $constrained->cacheScope('level:1'));

    expect($unscoped->cache)->toBe('bypass')
        ->and($scoped->cache)->toBe('hit')
        ->and(pluck($scoped, 'name'))->toBe(['Algebra']);
});

it('reads fresh data and refreshes the entry without the cache', function (): void {
    Data::query(cachedSubjects(), context: QueryContext::for($this->admin));

    $fresh = Data::query(cachedSubjects(), context: QueryContext::for($this->admin)->withoutCache());

    expect($fresh->cache)->toBe('miss')
        ->and(Data::query(cachedSubjects(), context: QueryContext::for($this->admin))->cache)->toBe('hit');
});

it('authorizes and redacts cached records for every caller', function (): void {
    $source = cachedSubjects(CacheIsolation::Shared, FieldSecurity::make([FieldAccess::make('code', read: fn (?User $user): bool => $user?->is_admin === true)]));
    Data::find($source, $this->subjects['chemistry']->id, QueryContext::for($this->admin));

    $adminRecord = Data::find($source, $this->subjects['algebra']->id, QueryContext::for($this->admin));
    $teacherRecord = Data::find($source, $this->subjects['algebra']->id, QueryContext::for($this->teacher));

    expect($teacherRecord->cache)->toBe('hit')
        ->and($teacherRecord->record()->code)->toBeNull()
        ->and($adminRecord->record()->code)->toBe('MTH101')
        ->and(fn () => Data::find($source, $this->subjects['chemistry']->id, QueryContext::for($this->teacher)))->toThrow(AuthorizationException::class);
});

it('caches aggregates', function (): void {
    $aggregation = new Aggregation;
    Data::aggregate(cachedSubjects(), $aggregation, context: QueryContext::for($this->admin));

    $queries = queriesDuring(function () use ($aggregation, &$result): void {
        $result = Data::aggregate(cachedSubjects(), $aggregation, context: QueryContext::for($this->admin));
    });

    expect($result->value)->toBe(5)
        ->and($queries)->toBe([]);
});

it('does not cache cursor pages or when caching is switched off', function (): void {
    $cursor = Source::define(Model::make(Subject::class), Pagination::cursor(2), Cached::make())->with(Name::make('cursor-subjects'));

    expect(Data::query($cursor, context: QueryContext::system())->cache)->toBe('bypass');

    $this->rebuildApplication(['gcego-data.cache.enabled' => false]);
    seedSubjects();

    expect(Data::query(cachedSubjects(), context: QueryContext::system())->cache)->toBe('bypass');
});

it('requires cached sources to be named', function (): void {
    expect(fn () => Data::definition(Source::define(Model::make(Subject::class), Cached::make())))->toThrow(InvalidSourceException::class, 'cached sources need a unique Name');
});

it('never shares entries between differently defined sources of the same name', function (): void {
    $published = Source::define(Model::make(Subject::class), Constraints::make([Filter::make('is_published', true)]), Cached::make())->with(Name::make('subjects'));
    $all = Source::define(Model::make(Subject::class), Cached::make())->with(Name::make('subjects'));

    Data::query($published, context: QueryContext::system());

    expect(Data::query($all, context: QueryContext::system())->pagination->total)->toBe(5);
});
