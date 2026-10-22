<?php

declare(strict_types=1);

use Gcego\Data\Caching\CacheIsolation;
use Gcego\Data\Contracts\TenantResolver;
use Gcego\Data\Definitions\Aggregates;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\TenantScoped;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\MissingTenantException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Has;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\CurrentTenant;
use Gcego\Data\Tests\Fixtures\Models\Bed;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Gcego\Data\Tests\Fixtures\Models\Ward;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->app->instance(TenantResolver::class, $this->tenant = new CurrentTenant);
});

function seedWards(): array
{
    return [
        'cardio' => Ward::query()->create(['hospital_id' => 1, 'name' => 'Cardiology', 'beds' => 20]),
        'onco' => Ward::query()->create(['hospital_id' => 1, 'name' => 'Oncology', 'beds' => 12]),
        'radio' => Ward::query()->create(['hospital_id' => 2, 'name' => 'Radiology', 'beds' => 8]),
    ];
}

function wards(mixed ...$nodes): Source
{
    return Source::define(Model::make(Ward::class), Sort::make('name', 'asc'), Writable::make(['name' => 'required|string', 'beds' => 'integer|min:0']), ...$nodes);
}

it('limits reads of tenant-owned models to the current tenant', function (): void {
    seedWards();
    $this->tenant->tenant = 2;

    expect(pluck(systemQuery(wards()), 'name'))->toBe(['Radiology']);
});

it('limits sources that declare TenantScoped', function (): void {
    seedSubjects();
    $this->tenant->tenant = 2;

    expect(pluck(systemQuery(Source::define(Model::make(Subject::class), Sort::make('name', 'asc'), TenantScoped::make('institution_id'))), 'name'))->toBe(['Drama', 'Economics'])
        ->and(pluck(systemQuery(Source::define(DatabaseTable::make('subjects'), Sort::make('name', 'asc'), TenantScoped::make('institution_id'))), 'name'))->toBe(['Drama', 'Economics'])
        ->and(pluck(systemQuery(Source::define(Items::make([['id' => 1, 'institution_id' => 1], ['id' => 2, 'institution_id' => 2]]), TenantScoped::make('institution_id'))), 'id'))->toBe([2]);
});

it('fails closed with 403 when there is no current tenant', function (): void {
    seedWards();

    expect(fn () => systemQuery(wards()))->toThrow(MissingTenantException::class)
        ->and((new MissingTenantException)->getStatusCode())->toBe(403);
});

it('acts for the context tenant, or across tenants only when asked', function (): void {
    seedWards();
    $this->tenant->tenant = 1;

    expect(pluck(Data::query(wards(), context: QueryContext::system()->forTenant(2)), 'name'))->toBe(['Radiology'])
        ->and(pluck(Data::query(wards(), context: QueryContext::system()->acrossTenants()), 'name'))->toBe(['Cardiology', 'Oncology', 'Radiology']);
});

it('reports a record of another tenant as not found', function (): void {
    $wards = seedWards();
    $this->tenant->tenant = 1;

    expect(Data::find(wards(), $wards['radio']->id, QueryContext::system())->found())->toBeFalse()
        ->and(fn () => Data::update(wards(), $wards['radio']->id, ['beds' => 0], QueryContext::system()))->toThrow(RecordNotFoundException::class)
        ->and(fn () => Data::delete(wards(), $wards['radio']->id, QueryContext::system()))->toThrow(RecordNotFoundException::class);

    $this->assertDatabaseHas('wards', ['id' => $wards['radio']->id, 'beds' => 8]);
});

it('stamps created records with the current tenant', function (): void {
    $this->tenant->tenant = 7;
    $this->actingAs(User::query()->create(['name' => 'Nurse']));

    $result = Data::create(wards(), ['name' => 'Maternity', 'beds' => 30]);

    expect($result->record->hospital_id)->toBe(7);
    $this->assertDatabaseHas('wards', ['name' => 'Maternity', 'hospital_id' => 7]);
});

it('never lets input set the tenant column', function (): void {
    $this->tenant->tenant = 7;

    expect(fn () => Data::create(wards(), ['name' => 'Maternity', 'hospital_id' => 2], QueryContext::system()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('wards', 0);
});

it('refuses to create tenant-owned records without a tenant, even across tenants', function (): void {
    expect(fn () => Data::create(wards(), ['name' => 'Maternity'], QueryContext::system()->acrossTenants()))->toThrow(MissingTenantException::class);
    $this->assertDatabaseCount('wards', 0);
});

it('rejects a writable tenant column', function (): void {
    expect(fn () => Data::definition(Source::define(Model::make(Subject::class), TenantScoped::make('institution_id'), Writable::make(['institution_id']))))->toThrow(InvalidSourceException::class, 'cannot be writable');
});

it('never returns or matches related records of another tenant', function (): void {
    $wards = seedWards();
    Bed::query()->create(['ward_id' => $wards['cardio']->id, 'hospital_id' => 1, 'label' => 'C-1']);
    // Inconsistent data: a bed of hospital 2 attached to a ward of hospital 1.
    Bed::query()->create(['ward_id' => $wards['cardio']->id, 'hospital_id' => 2, 'label' => 'FOREIGN-1']);
    $this->tenant->tenant = 1;
    $source = Source::define(
        Model::make(Ward::class),
        Sort::make('name'),
        Includables::make(['places']),
        Search::make(['name', 'places.label']),
        Filterables::make([Filterable::make('bed', [StringType::make(), Column::make('places.label')]), Filterable::make('places', [Has::make()])]),
        Aggregates::make([RelationAggregate::count('places')]),
    );

    $included = systemQuery($source, ['include' => 'places'])->items()[0];
    $record = Data::findOrFail($source, $wards['cardio']->id, QueryContext::system(), ['include' => 'places']);

    expect($included->places->pluck('label')->all())->toBe(['C-1'])
        ->and($record->places->pluck('label')->all())->toBe(['C-1'])
        ->and($included->places_count)->toBe(1)
        ->and(systemQuery($source, ['search' => 'FOREIGN'])->count())->toBe(0)
        ->and(systemQuery($source, ['filter' => ['bed' => 'FOREIGN-1']])->count())->toBe(0)
        ->and(pluck(systemQuery($source, ['filter' => ['places' => ['has' => '1']]]), 'name'))->toBe(['Cardiology']);
});

it('keys shared cache entries by tenant for tenant-scoped sources', function (): void {
    seedWards();
    $source = Source::define(Name::make('shared-wards'), Model::make(Ward::class), Cached::make(isolation: CacheIsolation::Shared));
    $this->tenant->tenant = 1;
    Data::query($source, context: QueryContext::system());

    $this->tenant->tenant = 2;
    $other = Data::query($source, context: QueryContext::system());

    expect($other->cache)->toBe('miss')
        ->and(pluck($other, 'name'))->toBe(['Radiology']);
});
