<?php

declare(strict_types=1);

use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Limits;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Events\SlowQueryDetected;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\LimitExceededException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Performance\StatementTimeout;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('records the statements of a profiled call without bindings', function (): void {
    seedSubjects();

    $result = Data::query(Source::define(Model::make(Subject::class)), ['filter' => []], QueryContext::system()->profiled());

    expect($result->profile?->count())->toBe(2)
        ->and($result->profile?->statements[0]['sql'])->toContain('select count(*)')
        ->and(json_encode($result->profile?->toArray()))->not->toContain('Algebra')
        ->and($result->profile?->suspectsNPlusOne())->toBeFalse()
        ->and($result->meta()['profile']['queries'] ?? null)->toBe(2);
});

it('flags statements repeated per record as a likely N+1', function (): void {
    seedSubjects();
    $source = Source::define(Model::make(Subject::class), Sort::make('name', 'asc'), Transform::make(fn (Subject $subject): int => $subject->topics->count()));

    $result = Data::query($source, context: QueryContext::system()->profiled());

    expect($result->profile?->suspectsNPlusOne())->toBeTrue()
        ->and(array_values($result->profile?->duplicates() ?? []))->toBe([5]);
});

it('reports slow calls', function (): void {
    $this->rebuildApplication(['gcego-data.performance.slow_query_ms' => 1]);
    Event::fake([SlowQueryDetected::class]);
    $slow = Source::define(Items::make(function (): array {
        $until = hrtime(true) + 3_000_000;

        while (hrtime(true) < $until) {
            // Busy-wait 3 ms: deterministic without sleeping.
        }

        return [['id' => 1]];
    }));

    Data::query($slow, context: QueryContext::system());

    Event::assertDispatched(SlowQueryDetected::class, fn (SlowQueryDetected $event): bool => $event->source === 'collection' && $event->operation === 'query' && $event->durationMs >= 3);
});

it('turns lazy loading on returned models into a violation when enabled', function (): void {
    $this->rebuildApplication(['gcego-data.performance.prevent_lazy_loading' => true]);
    seedSubjects();

    $subject = Data::query(Source::define(Model::make(Subject::class)), context: QueryContext::system())->items()[0];

    expect(fn () => $subject->topics)->toThrow(LazyLoadingViolationException::class);
});

it('refuses collection sources that load too many items', function (): void {
    $source = Source::define(Items::make(array_map(fn (int $id): array => ['id' => $id], range(1, 11))), Limits::make(maxItems: 10));

    expect(fn () => Data::query($source, context: QueryContext::system()))->toThrow(LimitExceededException::class, 'more than 10 items');
});

it('refuses pages beyond the deepest allowed offset', function (): void {
    $source = Source::define(Model::make(Subject::class), Pagination::make(10), Limits::make(maxOffset: 20));

    expect(fn () => Data::query($source, ['page' => 4], QueryContext::system()))->toThrow(InvalidQueryException::class, 'beyond the deepest page')
        ->and(Data::query($source, ['page' => 3], QueryContext::system())->pagination->currentPage)->toBe(3);
});

it('uses the statement timeout of each driver and runs unchanged elsewhere', function (): void {
    $mysql = new MySqlConnection(fn () => null, '', '', ['driver' => 'mysql']);
    $pgsql = new PostgresConnection(fn () => null, '', '', ['driver' => 'pgsql']);

    expect(StatementTimeout::statements($mysql, 1500)[0] ?? null)->toBe('SET SESSION max_execution_time = 1500')
        ->and(StatementTimeout::statements($pgsql, 1500)[0] ?? null)->toBe('SET statement_timeout = 1500')
        ->and(StatementTimeout::statements(DB::connection(), 1500))->toBeNull()
        ->and(StatementTimeout::run(DB::connection(), 1500, 'subjects', fn (): string => 'ran'))->toBe('ran');
});
