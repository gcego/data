<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Filters\ValueType;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sortable;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Support\Facades\DB;

it('uses a fixed number of queries per page, whatever the page size', function (int $size): void {
    seedSubjects();
    $source = new Source(Subject::class, new With('topics.lessons'), new Paginate($size));

    expect(queriesDuring(fn () => systemQuery($source)))->toHaveCount(4);
})->with([1, 2, 5]);

it('constructs and compiles hundreds of sources without database access', function (): void {
    $queries = queriesDuring(function (): void {
        foreach (range(1, 300) as $index) {
            app(DataManager::class)->definition(new Source(Subject::class, new Filterable('level'), new Search('name'), new Paginate(($index % 50) + 1)));
        }
    });

    expect($queries)->toBe([]);
});

it('paginates deep into a large table', function (): void {
    $now = now()->toDateTimeString();
    DB::table('subjects')->insert(array_map(fn (int $index): array => ['name' => sprintf('Subject %05d', $index), 'level' => $index % 5, 'institution_id' => 1, 'created_at' => $now, 'updated_at' => $now], range(1, 5000)));

    $page = systemQuery(new Source(Subject::class, new Paginate(50)), ['page' => 80]);
    $cursor = systemQuery(new Source(Subject::class, Paginate::cursor(50)));

    expect($page->pagination->total)->toBe(5000)
        ->and($page->pagination->from)->toBe(3951)
        ->and(pluck($page, 'name')[0])->toBe('Subject 03951')
        ->and($cursor->count())->toBe(50)
        ->and($cursor->pagination->nextCursor)->not->toBeNull();
});

it('handles repeated filtered queries within a regression budget', function (): void {
    seedSubjects();
    $source = new Source(Subject::class, new Filterable('level', [FilterOperator::GreaterThanOrEqual], ValueType::Integer), new Search('name', 'code'), new Sortable('name'));
    $manager = app(DataManager::class);
    $start = hrtime(true);

    foreach (range(1, 200) as $index) {
        $manager->query($source, ['filter' => ['level' => ['gte' => (string) ($index % 3 + 1)]], 'search' => 'o', 'sort' => '-name'], QueryContext::system());
    }

    // A regression guard, not a benchmark (see benchmarks/run.php): 200 queries on in-memory SQLite.
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(5000.0);
});
