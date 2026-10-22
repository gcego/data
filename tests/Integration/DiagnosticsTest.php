<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Query\Query;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\Sortable;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Course;
use Gcego\Data\Tests\Fixtures\Models\Subject;

it('describes a source, its provider capabilities and its authorization', function (): void {
    $report = app(DataManager::class)->inspect(new Source(
        Subject::class,
        new Query(fn ($query) => $query),
        new Filterable('level'),
        new Search('name'),
        new Sortable('name'),
        new Sort('name'),
        new With('topics'),
        new Paginate(20),
    ));

    expect($report->isHealthy())->toBeTrue()
        ->and($report->source['model'])->toBe(Subject::class)
        ->and($report->source['capabilities'])->toBe(['fetch', 'records', 'relationships', 'cursor-pagination', 'explain'])
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
    expect(app(DataManager::class)->inspect(new Source(stdClass::class))->check('source')[0]->level)->toBe('error')
        ->and(app(DataManager::class)->inspect(new Source(Subject::class, new With('missing')))->check('provider')[0]->message)->toContain('"missing" is not a relationship')
        ->and(app(DataManager::class)->inspect(new Source(Course::class))->check('authorization')[0]->level)->toBe('warning')
        ->and(app(DataManager::class)->inspect(Source::table('subjects'))->check('authorization')[0]->message)->toContain('"gcego-data.query" ability is not defined')
        ->and(app(DataManager::class)->inspect(new Source(Course::class))->check('sorting')[0]->level)->toBe('warning')
        ->and(app(DataManager::class)->inspect(Source::collection([]))->source['capabilities'])->toBe(['fetch', 'records']);
});

it('explains queries without executing them, and shows values only in debug mode', function (): void {
    $source = new Source(Subject::class, new Filterable('level'), new Search('name'));

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
