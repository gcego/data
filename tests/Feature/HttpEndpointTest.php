<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    seedSubjects();

    $source = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('level', [Integer::make(), Equal::make(), In::make()])]), Search::make(['name']), Sortables::make(['name']));

    // What an application route (or a future gcego/tables component) writes: no query code.
    Route::middleware('web')->get('/subjects', fn (Request $request, DataManager $data) => $data->query($source, $request));
    Route::middleware('web')->get('/subjects/{subject}', fn (string $subject, DataManager $data) => $data->findOrFail($source, $subject));
});

it('serves filtered, searched, sorted pages as JSON', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Teacher']))
        ->getJson('/subjects?filter[level]=2&search=y&sort=-name&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Chemistry')
        ->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.last_page', 2);
});

it('answers 400 for rejected input, 403 when unauthorized and 404 for missing records', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Teacher']));

    $this->getJson('/subjects?filter[institution_id]=2')->assertStatus(400);
    $this->getJson('/subjects?sort=password')->assertStatus(400);
    $this->getJson('/subjects?page=0')->assertStatus(400);
    $this->getJson('/subjects/999')->assertNotFound();
    $this->getJson('/subjects/abc')->assertNotFound();
    $this->getJson('/subjects/3')->assertForbidden();
    $this->getJson('/subjects/1')->assertOk()->assertJsonPath('name', 'Algebra');
});

it('denies guests', function (): void {
    $this->getJson('/subjects')->assertForbidden();
});

it('ignores request parameters that try to escalate privileges', function (): void {
    $this->getJson('/subjects?authorize=0&system=1&context=system&tenant=2')->assertForbidden();
});

it('caps excessive page sizes', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Teacher']))
        ->getJson('/subjects?per_page=100000')
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 100);
});
