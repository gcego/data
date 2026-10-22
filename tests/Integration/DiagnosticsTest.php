<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\Query;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Subject;

it('describes a source, its provider capabilities and its authorization', function (): void {
    $report = app(DataManager::class)->inspect(Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn ($query) => $query)]), Filterables::make([Filterable::make('level', [StringType::make(), Equal::make(), In::make()])]), Search::make(['name']), Sort::make('name', 'asc'), Sortables::make(['name']), Relations::make(['topics']), Pagination::make(20)));

    expect($report->isHealthy())->toBeTrue()
        ->and($report->source['model'])->toBe(Subject::class)
        ->and($report->source['capabilities'])->toBe(['fetch', 'records', 'relationships', 'cursor-pagination', 'explain', 'aggregation', 'mutations'])
        ->and($report->source['filterable']['level']['operators'])->toBe(['eq', 'in'])
        ->and($report->source['search'])->toBe(['fields' => ['name'], 'strategy' => null])
        ->and($report->source['sortable'])->toBe(['name' => 'name'])
        ->and($report->source['with'])->toBe(['topics'])
        ->and($report->source['constraints'])->toBe(1)
        ->and($report->check('authorization')[0]->message)->toContain('Subject policy')
        ->and($report->check('pagination')[0]->message)->toBe('Pages pagination, 20 per page.')
        ->and((string) json_encode($report->toArray()))->not->toContain('Closure');
});

it('reports definition errors and missing policies without throwing', function (): void {
    expect(app(DataManager::class)->inspect(Source::define(Model::make(stdClass::class)))->check('source')[0]->level)->toBe('error')
        ->and(app(DataManager::class)->inspect(Source::define(Model::make(Subject::class), Relations::make(['missing'])))->check('provider')[0]->message)->toContain('"missing" is not a relationship')
        ->and(app(DataManager::class)->inspect(Source::define(Model::make(Course::class)))->check('authorization')[0]->level)->toBe('warning')
        ->and(app(DataManager::class)->inspect(Source::define(DatabaseTable::make('subjects')))->check('authorization')[0]->message)->toContain('"gcego-data.query" ability is not defined')
        ->and(app(DataManager::class)->inspect(Source::define(Model::make(Course::class)))->check('sorting')[0]->level)->toBe('warning')
        ->and(app(DataManager::class)->inspect(Source::define(Items::make([])))->source['capabilities'])->toBe(['fetch', 'records', 'aggregation']);
});

it('explains queries without executing them, and shows values only in debug mode', function (): void {
    $source = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('level', [StringType::make(), Equal::make(), In::make()])]), Search::make(['name']));

    $queries = queriesDuring(function () use ($source, &$explained, &$debug): void {
        $explained = app(DataManager::class)->explain($source, ['filter' => ['level' => '2'], 'search' => 'bio']);
        config()->set('app.debug', true);
        $this->rebuildApplication(['app.debug' => true]);
        $debug = app(DataManager::class)->explain($source, ['filter' => ['level' => '2']], includeBindings: true);
    });

    expect($explained['sql'])->toContain('"subjects"."level" = ?')
        ->and($explained['bindings'])->toBe(2)
        ->and($explained)->not->toHaveKey('values')
        ->and($debug['values'] ?? null)->toBe(['2'])
        ->and(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select')))->toBe([]);
});
