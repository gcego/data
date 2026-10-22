<?php

declare(strict_types=1);

use Gcego\Data\Contracts\TenantResolver;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Facades\Data;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\CurrentTenant;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;
use Gcego\Data\Tests\Fixtures\Models\Ward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->app->instance(TenantResolver::class, $this->tenant = new CurrentTenant);
    $subjects = Source::define(Model::make(Subject::class), Writable::make(['name' => 'required|string|max:40', 'level' => 'integer|between:1,6']));
    $wards = Source::define(Model::make(Ward::class), Writable::make(['name' => 'required|string', 'beds' => 'integer|min:0']));

    // What an application route writes: no validation, authorization or tenancy code.
    Route::middleware('web')->post('/subjects', fn (Request $request) => response()->json(Data::create($subjects, $request), 201));
    Route::middleware('web')->patch('/wards/{ward}', fn (Request $request, string $ward) => Data::update($wards, $ward, $request));
    Route::middleware('web')->delete('/wards/{ward}', fn (string $ward) => Data::delete($wards, $ward));
});

it('creates a record and answers 201', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Admin', 'is_admin' => true]))
        ->postJson('/subjects', ['name' => 'Physics', 'level' => 2])
        ->assertCreated()
        ->assertJsonPath('operation', 'create')
        ->assertJsonPath('data.name', 'Physics');

    $this->assertDatabaseHas('subjects', ['name' => 'Physics', 'level' => 2]);
});

it('answers 422 with the field errors for invalid or unknown fields', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Admin', 'is_admin' => true]))
        ->postJson('/subjects', ['name' => '', 'is_published' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_published']);

    $this->assertDatabaseCount('subjects', 0);
});

it('answers 403 when the policy denies the write', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Teacher']))
        ->postJson('/subjects', ['name' => 'Physics'])
        ->assertForbidden();

    $this->assertDatabaseCount('subjects', 0);
});

it('answers 404 for a record of another tenant and changes nothing', function (): void {
    $foreign = Ward::query()->create(['hospital_id' => 2, 'name' => 'Radiology', 'beds' => 8]);
    $this->tenant->tenant = 1;
    $this->actingAs(User::query()->create(['name' => 'Nurse']));

    $this->patchJson('/wards/'.$foreign->id, ['beds' => 0])->assertNotFound();
    $this->deleteJson('/wards/'.$foreign->id)->assertNotFound();

    $this->assertDatabaseHas('wards', ['id' => $foreign->id, 'beds' => 8]);
});

it('answers 403 when there is no current tenant', function (): void {
    $ward = Ward::query()->create(['hospital_id' => 1, 'name' => 'Cardiology']);

    $this->actingAs(User::query()->create(['name' => 'Nurse']))
        ->patchJson('/wards/'.$ward->id, ['beds' => 3])
        ->assertForbidden()
        ->assertJsonPath('message', 'No tenant context is available for this request.');
});
