<?php

declare(strict_types=1);

use Gcego\Core\Gcego;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\DataConfig;
use Gcego\Data\DataManager;
use Gcego\Data\DataServiceProvider;
use Gcego\Data\Definition\SourceCompiler;
use Gcego\Data\Facades\Data;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Records\RecordResolver;
use Gcego\Data\Security\GateDataAuthorizer;
use Gcego\Data\Security\ScopeRegistry;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

it('binds shared services', function (string $service): void {
    expect(app($service))->toBeInstanceOf($service)
        ->and(app($service))->toBe(app($service));
})->with([DataManager::class, SourceCompiler::class, QueryPipeline::class, RecordResolver::class, ProviderRegistry::class, ScopeRegistry::class, DataConfig::class]);

it('registers the built-in providers and the default authorizer', function (): void {
    expect(app(ProviderRegistry::class)->names())->toBe(['eloquent', 'query', 'collection'])
        ->and(app(DataAuthorizer::class))->toBeInstanceOf(GateDataAuthorizer::class)
        ->and(ServiceProvider::pathsToPublish(DataServiceProvider::class, 'gcego-data-config'))->toHaveCount(1);
});

it('takes part in the gcego/core package lifecycle', function (): void {
    expect(app(Gcego::class)->bootResult()?->packages)->toContain('gcego/data')
        ->and(app(Gcego::class)->hasCapability('data.eloquent'))->toBeTrue();
});

it('does not touch the database while the framework boots', function (): void {
    $this->rebuildApplication(withSchema: false);

    expect(DB::getConnections())->toBe([]);

    app(DataManager::class);
    new Source(Subject::class, new Filter('is_published', true));

    expect(DB::getConnections())->toBe([]);
});

it('reads its configuration', function (): void {
    $this->rebuildApplication(['gcego-data.pagination.max_per_page' => 30, 'gcego-data.queries.strict' => false]);

    expect(app(DataConfig::class)->maxPerPage)->toBe(30)
        ->and(app(DataConfig::class)->strict)->toBeFalse();
});

it('resolves the data manager through the Data facade', function (): void {
    seedSubjects();

    expect(Data::getFacadeRoot())->toBe(app(DataManager::class))
        ->and(pluck(Data::query(new Source(Subject::class, new Filter('level', 3)), context: QueryContext::system()), 'name'))->toBe(['Drama', 'Economics']);
});
