<?php

declare(strict_types=1);

use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\DataManager;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Property;
use Gcego\Data\Query\QueryPlan;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\Sortable;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Transform\Transform;
use Illuminate\Pagination\LengthAwarePaginator;

beforeEach(fn () => seedSubjects());

it('sorts ascending, descending and by several columns', function (): void {
    $source = new Source(Subject::class, new Sortable('name', 'level', ['newest' => 'created_at']));

    expect(pluck(systemQuery($source, ['sort' => 'name']), 'name'))->toBe(['Algebra', 'Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and(pluck(systemQuery($source, ['sort' => '-name']), 'name'))->toBe(['Economics', 'Drama', 'Chemistry', 'Biology', 'Algebra'])
        ->and(pluck(systemQuery($source, ['sort' => '-level,name']), 'name'))->toBe(['Drama', 'Economics', 'Biology', 'Chemistry', 'Algebra'])
        ->and(systemQuery($source, ['sort' => '-level'])->applied['sort'])->toBe(['level']);
});

it('applies default sorts only when none are requested', function (): void {
    $source = new Source(Subject::class, new Sort('level', 'desc'), new Sort('name'), new Sortable('name'));

    expect(pluck(systemQuery($source), 'name'))->toBe(['Drama', 'Economics', 'Biology', 'Chemistry', 'Algebra'])
        ->and(pluck(systemQuery($source, ['sort' => 'name']), 'name'))->toBe(['Algebra', 'Biology', 'Chemistry', 'Drama', 'Economics']);
});

it('rejects sorting by columns that are not sortable', function (): void {
    systemQuery(new Source(Subject::class, new Sortable('name')), ['sort' => 'institution_id']);
})->throws(InvalidSortException::class, 'not a sortable field');

it('keeps pages stable when sort values repeat', function (): void {
    foreach (range(1, 30) as $index) {
        Subject::query()->create(['name' => 'Same', 'level' => 9, 'institution_id' => 1]);
    }

    $source = new Source(Subject::class, new Sort('level', 'desc'), new Paginate(7));
    $seen = [];

    foreach (range(1, 5) as $page) {
        $seen = [...$seen, ...pluck(systemQuery($source, ['page' => $page]), 'id')];
    }

    expect($seen)->toHaveCount(35)
        ->and(array_unique($seen))->toHaveCount(35)
        ->and(app(DataManager::class)->explain($source)['sql'])->toContain('order by "subjects"."level" desc, "subjects"."id" asc');
});

it('uses the default page size and caps requested sizes', function (): void {
    foreach (range(1, 120) as $index) {
        Subject::query()->create(['name' => 'Extra '.$index, 'institution_id' => 1]);
    }

    expect(systemQuery(new Source(Subject::class))->count())->toBe(25)
        ->and(systemQuery(new Source(Subject::class), ['per_page' => 500])->count())->toBe(100)
        ->and(systemQuery(new Source(Subject::class, new Paginate(10, max: 20)), ['per_page' => 50])->pagination->perPage)->toBe(20);
});

it('rejects invalid page parameters', function (array $input): void {
    systemQuery(new Source(Subject::class), $input);
})->with([[['page' => '0']], [['page' => '-3']], [['per_page' => '0']], [['per_page' => 'all']]])->throws(InvalidQueryException::class);

it('returns empty pages past the end and for empty results', function (): void {
    $past = systemQuery(new Source(Subject::class, new Paginate(2)), ['page' => 9]);
    $empty = systemQuery(new Source(Subject::class, new Filter('name', 'Nothing')));

    expect($past->isEmpty())->toBeTrue()
        ->and($past->pagination->total)->toBe(5)
        ->and($past->pagination->currentPage)->toBe(9)
        ->and($empty->pagination->total)->toBe(0)
        ->and($empty->pagination->lastPage)->toBe(1)
        ->and($empty->pagination->from)->toBeNull();
});

it('skips the count query with simple pagination', function (): void {
    $queries = queriesDuring(function () use (&$result): void {
        $result = systemQuery(new Source(Subject::class, Paginate::simple(2)), ['page' => 2]);
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->not->toContain('count(')
        ->and($result->pagination->mode)->toBe(PaginationMode::Simple)
        ->and($result->pagination->total)->toBeNull()
        ->and($result->pagination->hasMorePages)->toBeTrue()
        ->and(pluck($result, 'name'))->toBe(['Chemistry', 'Drama']);
});

it('walks every record with cursor pagination, even with repeated sort values', function (): void {
    foreach (range(1, 9) as $index) {
        Subject::query()->create(['name' => 'Same', 'level' => 2, 'institution_id' => 1]);
    }

    $source = new Source(Subject::class, new Sort('level', 'desc'), Paginate::cursor(4));
    $seen = [];
    $cursor = null;

    do {
        $result = systemQuery($source, ['cursor' => $cursor]);
        $seen = [...$seen, ...pluck($result, 'id')];
        $cursor = $result->pagination->nextCursor;
    } while ($cursor !== null);

    expect($seen)->toHaveCount(14)
        ->and(array_unique($seen))->toHaveCount(14)
        ->and($result->pagination->total)->toBeNull();
});

it('rejects cursors on page-number sources and tampered cursors', function (): void {
    $cursor = base64_encode((string) json_encode(['subjects.secret' => 1, '_pointsToNextItems' => true]));

    expect(fn () => systemQuery(new Source(Subject::class), ['cursor' => 'abc']))->toThrow(InvalidQueryException::class, 'not supported by this source')
        ->and(fn () => systemQuery(new Source(Subject::class, Paginate::cursor(2)), ['cursor' => rtrim($cursor, '=')]))->toThrow(InvalidQueryException::class, 'does not match the current ordering');
});

it('does not offer cursor pagination for collections', function (): void {
    systemQuery(Source::collection([['id' => 1]], Paginate::cursor()));
})->throws(InvalidSourceException::class, 'do not support cursor pagination');

it('reports unsupported cursor pagination for custom providers', function (): void {
    app(DataManager::class)->provider('memory', new class implements DataProvider
    {
        public function prepare(SourceDefinition $source): void {}

        public function fetch(SourceDefinition $source, QueryPlan $plan, array $constraints): LengthAwarePaginator
        {
            return new LengthAwarePaginator([], 0, $plan->perPage);
        }
    });

    systemQuery(new Source(new Property(Source::Provider, 'memory'), Paginate::cursor()));
})->throws(UnsupportedProviderException::class, 'does not support cursor pagination');

it('computes cursors before transforms remove the ordering columns', function (): void {
    $source = new Source(Subject::class, new Sort('name'), Paginate::cursor(2), Transform::only('code'));

    $first = systemQuery($source);
    $second = systemQuery($source, ['cursor' => $first->pagination->nextCursor]);

    expect($first->items())->toBe([['code' => 'MTH101'], ['code' => 'BIO101']])
        ->and($second->items())->toBe([['code' => 'CHM201'], ['code' => null]]);
});
