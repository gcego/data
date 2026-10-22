<?php

declare(strict_types=1);

namespace Gcego\Data\Transactions;

use Closure;
use Gcego\Data\DataConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Atomic units of work with rollback and retry:
 *
 *     Data::transaction(function () use ($data) {
 *         $invoice = Data::create($invoices, $input)->record;
 *         Data::create($lines, [...$line, 'invoice_id' => $invoice->id]);
 *     });
 *
 * Every gcego/data write runs in one. An exception rolls everything back and
 * is rethrown. An outermost transaction that fails with a deadlock or
 * serialization failure is retried with exponential backoff and jitter
 * (gcego-data.transactions). Nested calls join the outer transaction as a
 * savepoint and are never retried on their own (the outer one is).
 */
final readonly class TransactionManager
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private DataConfig $config,
        private Container $container,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $callback
     * @return T
     *
     * @throws Throwable The callback's exception, after rollback (and retries).
     */
    public function run(Closure $callback, ?TransactionOptions $options = null): mixed
    {
        $options ??= new TransactionOptions;
        $connection = $this->connections->connection($options->connection);

        if ($connection->transactionLevel() > 0) {
            return $connection->transaction(static fn (): mixed => $callback($connection));
        }

        $attempts = $options->attempts ?? $this->config->transactionAttempts;

        for ($attempt = 1; ; $attempt++) {
            $this->isolate($connection, $options->isolation, before: true);
            $connection->beginTransaction();

            try {
                $this->isolate($connection, $options->isolation, before: false);
                $result = $callback($connection);
                $connection->commit();

                return $result;
            } catch (Throwable $exception) {
                $this->rollBack($connection);

                if ($attempt >= $attempts || ! $this->retryable($exception, $options)) {
                    throw $exception;
                }

                $this->backoff($attempt, $options);
            }
        }
    }

    public function retryable(Throwable $exception, TransactionOptions $options): bool
    {
        foreach ($options->retryOn as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        $detector = $this->container->bound(ConcurrencyErrorDetectorContract::class)
            ? $this->container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($detector instanceof ConcurrencyErrorDetectorContract && $detector->causedByConcurrencyError($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The isolation statement for a driver, and whether it must run inside the transaction.
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function isolationStatement(ConnectionInterface $connection, IsolationLevel $level): ?array
    {
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;

        return match ($driver) {
            // Applies to the next transaction only.
            'mysql', 'mariadb' => ['SET TRANSACTION ISOLATION LEVEL '.$level->value, false],
            // Must be the first statement of the transaction.
            'pgsql' => ['SET TRANSACTION ISOLATION LEVEL '.$level->value, true],
            // Session-level; SQL Server resets it per batch scope of the transaction.
            'sqlsrv' => ['SET TRANSACTION ISOLATION LEVEL '.$level->value, false],
            default => null,
        };
    }

    private function isolate(ConnectionInterface $connection, ?IsolationLevel $level, bool $before): void
    {
        if ($level === null) {
            return;
        }

        $statement = self::isolationStatement($connection, $level);

        if ($statement !== null && $statement[1] !== $before) {
            $connection->statement($statement[0]);
        }
    }

    private function rollBack(ConnectionInterface $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // A lost connection already discarded the transaction.
        }
    }

    private function backoff(int $attempt, TransactionOptions $options): void
    {
        $base = $options->backoffMs ?? $this->config->transactionBackoffMs;

        if ($base === 0) {
            return;
        }

        $delay = min($this->config->transactionMaxBackoffMs, $base * (2 ** ($attempt - 1)));
        usleep((int) (($delay / 2 + random_int(0, max(0, (int) ($delay / 2)))) * 1000));
    }
}
