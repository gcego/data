<?php

declare(strict_types=1);

namespace Gcego\Data\Transactions;

use Gcego\Data\Exceptions\InvalidSourceException;
use Throwable;

/**
 * How one transaction runs:
 *
 *     new TransactionOptions(attempts: 5, isolation: IsolationLevel::Serializable)
 *
 * Null values use gcego-data.transactions. Retries happen only for an
 * outermost transaction, and only after errors that are safe to retry: a
 * deadlock or serialization failure (Laravel's concurrency error detector),
 * or one of $retryOn. The callback must therefore be safe to run again
 * (no side effects outside the database before the transaction commits;
 * use events, which gcego/data dispatches after commit).
 */
final readonly class TransactionOptions
{
    /**
     * @param  list<class-string<Throwable>>  $retryOn  Additional retryable exceptions.
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public ?string $connection = null,
        public ?int $attempts = null,
        public ?int $backoffMs = null,
        public ?IsolationLevel $isolation = null,
        public array $retryOn = [],
    ) {
        if (($attempts !== null && $attempts < 1) || ($backoffMs !== null && $backoffMs < 0)) {
            throw InvalidSourceException::invalidDefinition('TransactionOptions', 'attempts must be at least 1 and backoffMs at least 0.');
        }
    }

    public function onConnection(?string $connection): self
    {
        return new self($connection, $this->attempts, $this->backoffMs, $this->isolation, $this->retryOn);
    }
}
