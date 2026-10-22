<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gcego\Data\Aggregation\AggregateFunction;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Aggregation\TimeBucket;
use Gcego\Data\DataManager;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\ValueType;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\InstitutionDataScope;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Auth\Access\AuthorizationException;

function aggregate(Source $source, Aggregation $aggregation, array $input = [], ?QueryContext $context = null): AggregateResult
{
    return app(DataManager::class)->aggregate($source, $aggregation, $input, $context ?? QueryContext::system());
}

beforeEach(function (): void {
    $this->subjects = seedSubjects();
});

it('counts, sums, averages and finds extremes in the database', function (): void {
    $source = new Source(Subject::class);
    $queries = queriesDuring(function () use ($source, &$results): void {
        $results = [
            aggregate($source, new Aggregation)->value,
            aggregate($source, new Aggregation(AggregateFunction::Sum, 'level'))->value,
            aggregate($source, new Aggregation(AggregateFunction::Average, 'level'))->value,
            aggregate($source, new Aggregation(AggregateFunction::Minimum, 'level'))->value,
            aggregate($source, new Aggregation(AggregateFunction::Maximum, 'level'))->value,
        ];
    });

    expect($results)->toEqual([5, 11, 2.2, 1, 3])
        ->and($queries)->toHaveCount(5)
        ->and(implode(' ', $queries))->toContain('count(*)')
        ->and(implode(' ', $queries))->not->toContain('select * from');
});

it('groups by a column and by time buckets, bounded', function (): void {
    Subject::query()->whereKey($this->subjects['algebra']->id)->update(['created_at' => '2026-01-15 10:00:00']);
    Subject::query()->whereKey($this->subjects['biology']->id)->update(['created_at' => '2026-01-20 10:00:00']);
    Subject::query()->whereKey($this->subjects['chemistry']->id)->update(['created_at' => '2026-03-02 10:00:00']);
    Subject::query()->whereIn('id', [$this->subjects['drama']->id, $this->subjects['economics']->id])->update(['created_at' => '2025-12-31 23:00:00']);
    $source = new Source(Subject::class);

    expect(aggregate($source, new Aggregation(groupBy: 'level'))->pairs())->toBe(['1' => 1, '2' => 2, '3' => 2])
        ->and(aggregate($source, new Aggregation(dateColumn: 'created_at', bucket: TimeBucket::Month))->pairs())->toBe(['2025-12' => 2, '2026-01' => 2, '2026-03' => 1])
        ->and(aggregate($source, new Aggregation(dateColumn: 'created_at', bucket: TimeBucket::Year))->pairs())->toBe(['2025' => 2, '2026' => 3])
        ->and(aggregate($source, new Aggregation(dateColumn: 'created_at', bucket: TimeBucket::Day, from: CarbonImmutable::parse('2026-01-01'), to: CarbonImmutable::parse('2026-01-31 23:59:59')))->pairs())->toBe(['2026-01-15' => 1, '2026-01-20' => 1])
        ->and(aggregate($source, new Aggregation(AggregateFunction::Sum, 'level', groupBy: 'is_published'))->pairs())->toBe(['0' => 2, '1' => 9]);

    $limited = aggregate($source, new Aggregation(groupBy: 'level', limit: 2));

    expect($limited->groups)->toHaveCount(2)
        ->and($limited->truncated)->toBeTrue();
});

it('applies mandatory constraints, scopes, soft deletes and allowlisted filters', function (): void {
    app(DataManager::class)->scope(new InstitutionDataScope(1), Subject::class);
    $source = new Source(Subject::class, new Filterable('level', type: ValueType::Integer));

    expect(aggregate($source, new Aggregation)->value)->toBe(3)
        ->and(aggregate($source, new Aggregation, ['filter' => ['level' => '2']])->value)->toBe(2)
        ->and(aggregate(new Source(Subject::class, new Filter('is_published', true)), new Aggregation)->value)->toBe(2)
        ->and(fn () => aggregate($source, new Aggregation, ['filter' => ['code' => 'x']]))->toThrow(InvalidFilterException::class);
});

it('authorizes like a listing', function (): void {
    expect(fn () => app(DataManager::class)->aggregate(new Source(Subject::class), new Aggregation, context: QueryContext::for(null)))->toThrow(AuthorizationException::class);
});

it('aggregates collection sources in memory', function (): void {
    $source = Source::collection(fn () => [['level' => 1, 'at' => '2026-01-02'], ['level' => 2, 'at' => '2026-01-03'], ['level' => 2, 'at' => '2026-02-01']]);

    expect(aggregate($source, new Aggregation(AggregateFunction::Sum, 'level'))->value)->toBe(5)
        ->and(aggregate($source, new Aggregation(groupBy: 'level'))->pairs())->toBe(['1' => 1, '2' => 2])
        ->and(aggregate($source, new Aggregation(dateColumn: 'at', bucket: TimeBucket::Month))->pairs())->toBe(['2026-01' => 2, '2026-02' => 1]);
});

it('rejects untrusted or inconsistent aggregations', function (Closure $build): void {
    expect($build)->toThrow(InvalidQueryException::class);
})->with([
    'column injection' => [fn () => new Aggregation(AggregateFunction::Sum, 'level) from users --')],
    'relation path' => [fn () => new Aggregation(AggregateFunction::Sum, 'topics.id')],
    'sum without column' => [fn () => new Aggregation(AggregateFunction::Sum)],
    'bucket without date' => [fn () => new Aggregation(bucket: TimeBucket::Day)],
    'range without date column' => [fn () => new Aggregation(from: CarbonImmutable::now())],
    'reversed range' => [fn () => new Aggregation(dateColumn: 'created_at', from: CarbonImmutable::now(), to: CarbonImmutable::now()->subDay())],
    'too many groups' => [fn () => new Aggregation(groupBy: 'level', limit: 5000)],
]);

it('hands trusted callers the scoped, filtered builder to aggregate on, after authorization', function (): void {
    app(DataManager::class)->scope(new InstitutionDataScope(1), Subject::class);
    $source = new Source(Subject::class, new Filterable('level', type: ValueType::Integer));
    $data = app(DataManager::class);

    $all = $data->scopedQuery($source, [], QueryContext::system());
    $filtered = $data->scopedQuery($source, ['filter' => ['level' => '2']], QueryContext::system());

    expect($all->count())->toBe(3)
        ->and($filtered->count())->toBe(2)
        ->and(fn () => $data->scopedQuery($source, [], QueryContext::for(null)))->toThrow(AuthorizationException::class)
        ->and(fn () => $data->scopedQuery(Source::collection(fn () => []), [], QueryContext::system()))->toThrow(UnsupportedProviderException::class);
});
