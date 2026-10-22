<?php

declare(strict_types=1);

use Gcego\Data\Facades\Data;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Transactions\IsolationLevel;
use Gcego\Data\Transactions\TransactionManager;
use Gcego\Data\Transactions\TransactionOptions;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

it('rolls back every write when the unit of work fails', function (): void {
    expect(fn () => Data::transaction(function (): void {
        Subject::query()->create(['name' => 'Physics']);

        throw new RuntimeException('Payment failed');
    }))->toThrow(RuntimeException::class, 'Payment failed');

    $this->assertDatabaseCount('subjects', 0);
});

it('retries a transaction that hit a deadlock, then commits', function (): void {
    $attempts = 0;

    $result = Data::transaction(function () use (&$attempts): string {
        $attempts++;
        Subject::query()->create(['name' => 'Attempt '.$attempts]);

        if ($attempts < 3) {
            throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
        }

        return 'committed';
    }, new TransactionOptions(attempts: 3, backoffMs: 0));

    expect($result)->toBe('committed')
        ->and($attempts)->toBe(3);
    $this->assertDatabaseCount('subjects', 1);
    $this->assertDatabaseHas('subjects', ['name' => 'Attempt 3']);
});

it('gives up after the configured attempts', function (): void {
    $attempts = 0;

    expect(function () use (&$attempts): void {
        Data::transaction(function () use (&$attempts): void {
            $attempts++;

            throw new RuntimeException('Deadlock found when trying to get lock');
        }, new TransactionOptions(attempts: 2, backoffMs: 0));
    })->toThrow(RuntimeException::class);

    expect($attempts)->toBe(2);
});

it('does not retry errors that are not concurrency failures', function (): void {
    $attempts = 0;

    expect(function () use (&$attempts): void {
        Data::transaction(function () use (&$attempts): void {
            $attempts++;

            throw new LogicException('Invalid invoice');
        }, new TransactionOptions(attempts: 5, backoffMs: 0));
    })->toThrow(LogicException::class);

    expect($attempts)->toBe(1);
});

it('retries configured exception classes', function (): void {
    $attempts = 0;

    Data::transaction(function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new DomainException('Ledger busy');
        }
    }, new TransactionOptions(attempts: 2, backoffMs: 0, retryOn: [DomainException::class]));

    expect($attempts)->toBe(2);
});

it('joins an outer transaction instead of committing on its own', function (): void {
    expect(fn () => Data::transaction(function (): void {
        Data::transaction(fn () => Subject::query()->create(['name' => 'Inner']));

        throw new RuntimeException('Outer failed');
    }))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('subjects', 0);
});

it('uses the isolation statement of each driver', function (string $class, string $driver, ?array $expected): void {
    /** @var Connection $connection */
    $connection = new $class(fn () => null, '', '', ['driver' => $driver]);

    expect(TransactionManager::isolationStatement($connection, IsolationLevel::Serializable))->toBe($expected);
})->with([
    'mysql' => [MySqlConnection::class, 'mysql', ['SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', false]],
    'pgsql' => [PostgresConnection::class, 'pgsql', ['SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', true]],
    'sqlite' => [SQLiteConnection::class, 'sqlite', null],
]);
