<?php

declare(strict_types=1);

namespace Gcego\Data;

use Gcego\Core\Contracts\PackageInterface;
use Gcego\Core\Packages\PackageDescriptor;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Definition\SourceCompiler;
use Gcego\Data\Diagnostics\QueryInspector;
use Gcego\Data\Providers\CollectionProvider;
use Gcego\Data\Providers\EloquentProvider;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Providers\QueryBuilderProvider;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Query\QueryResolver;
use Gcego\Data\Records\RecordResolver;
use Gcego\Data\Security\GateDataAuthorizer;
use Gcego\Data\Security\ScopeRegistry;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel integration for gcego/data; also the "gcego/data" GCEGO package.
 *
 * Registers the data services as singletons that hold only registrations
 * (providers, scopes) and definition caches, never request data. Nothing
 * touches the database during registration or boot.
 *
 * gcego/core is its only GCEGO dependency: every other package builds on
 * DataManager (or the Data facade) to read application data.
 */
final class DataServiceProvider extends ServiceProvider implements PackageInterface
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/gcego-data.php', 'gcego-data');

        $this->app->singleton(DataConfig::class, static function (Application $app): DataConfig {
            $config = $app->make(Repository::class)->get('gcego-data', []);

            return DataConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(SourceCompiler::class);
        $this->app->singleton(QueryResolver::class);
        $this->app->singleton(ScopeRegistry::class);
        $this->app->singleton(DataAuthorizer::class, GateDataAuthorizer::class);
        $this->app->singleton(EloquentProvider::class);
        $this->app->singleton(QueryBuilderProvider::class);
        $this->app->singleton(CollectionProvider::class);

        $this->app->singleton(ProviderRegistry::class, static function (Application $app): ProviderRegistry {
            $providers = new ProviderRegistry($app);
            $providers->register('eloquent', EloquentProvider::class);
            $providers->register('query', QueryBuilderProvider::class);
            $providers->register('collection', CollectionProvider::class);

            return $providers;
        });

        $this->app->singleton(QueryPipeline::class);
        $this->app->singleton(RecordResolver::class);
        $this->app->singleton(QueryInspector::class, static fn (Application $app): QueryInspector => new QueryInspector(
            $app->make(SourceCompiler::class),
            $app->make(ProviderRegistry::class),
            $app->make(QueryResolver::class),
            $app->make(QueryPipeline::class),
            $app->make(DataConfig::class),
            $app->make(Gate::class),
            (bool) $app->make(Repository::class)->get('app.debug', false),
        ));
        $this->app->singleton(DataManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__).'/config/gcego-data.php' => $this->app->configPath('gcego-data.php')], 'gcego-data-config');
        }
    }

    public function descriptor(): PackageDescriptor
    {
        return new PackageDescriptor(
            'gcego/data',
            requires: ['gcego/core'],
            capabilities: ['data', 'data.eloquent', 'data.query', 'data.collection'],
            description: 'Declarative data sources with secure filtering, searching, sorting, pagination and scoped record resolution.',
        );
    }
}
