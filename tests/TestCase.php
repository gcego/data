<?php

declare(strict_types=1);

namespace Gcego\Data\Tests;

use Gcego\Core\GcegoServiceProvider;
use Gcego\Data\DataServiceProvider;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\Ward;
use Gcego\Data\Tests\Fixtures\Policies\SubjectPolicy;
use Gcego\Data\Tests\Fixtures\Policies\WardPolicy;
use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @var array<string, mixed>
     */
    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    /**
     * Rebuild the application with configuration overrides (keys like "gcego-data.queries.strict").
     *
     * @param  array<string, mixed>  $config
     */
    public function rebuildApplication(array $config = [], bool $withSchema = true): Application
    {
        $this->overrides = $config;
        $this->refreshApplication();

        if ($withSchema) {
            $this->createSchema();
        }

        /** @var Application */
        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [GcegoServiceProvider::class, DataServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('database.connections.archive', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->singleton(Tenancy::class);

        foreach ($this->overrides as $key => $value) {
            $app['config']->set($key, $value);
        }

        Gate::policy(Subject::class, SubjectPolicy::class);
        Gate::policy(Ward::class, WardPolicy::class);
    }

    private function createSchema(): void
    {
        Schema::create('users', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        foreach (['testing', 'archive'] as $connection) {
            Schema::connection($connection)->create('subjects', static function (Table $table): void {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable();
                $table->boolean('is_published')->default(false);
                $table->integer('level')->default(1);
                $table->unsignedInteger('institution_id')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('topics', static function (Table $table): void {
            $table->id();
            $table->foreignId('subject_id')->constrained();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('lessons', static function (Table $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('students', static function (Table $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('national_id');
            $table->unsignedInteger('institution_id');
            $table->timestamps();
        });

        Schema::create('enrolments', static function (Table $table): void {
            $table->ulid('id')->primary();
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('wards', static function (Table $table): void {
            $table->id();
            $table->unsignedInteger('hospital_id');
            $table->string('name');
            $table->integer('beds')->default(0);
            $table->timestamps();
        });

        Schema::create('beds', static function (Table $table): void {
            $table->id();
            $table->foreignId('ward_id');
            $table->unsignedInteger('hospital_id');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('courses', static function (Table $table): void {
            $table->string('code')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }
}
