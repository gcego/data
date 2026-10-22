<?php

declare(strict_types=1);

namespace Gcego\Data\Performance;

use Closure;
use Gcego\Data\Exceptions\QueryTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Runs reads under a statement timeout, restoring the session setting afterwards.
 *
 * - MySQL / MariaDB: max_execution_time / max_statement_time (SELECT statements).
 * - PostgreSQL: statement_timeout.
 * - Other drivers (SQLite, SQL Server): no server-side timeout; the callback runs as is.
 *
 * Timeout errors (MySQL 3024 / 1969, PostgreSQL 57014) become QueryTimeoutException.
 */
final class StatementTimeout
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws QueryTimeoutException
     */
    public static function run(ConnectionInterface $connection, ?int $timeoutMs, string $source, Closure $callback): mixed
    {
        $statements = $timeoutMs === null ? null : self::statements($connection, $timeoutMs);

        if ($statements === null) {
            return $callback();
        }

        [$set, $read, $restore] = $statements;
        $previous = $connection->selectOne($read);
        $connection->statement($set);

        try {
            return $callback();
        } catch (QueryException $exception) {
            throw self::isTimeout($exception) ? QueryTimeoutException::after($source, (int) $timeoutMs, $exception) : $exception;
        } finally {
            try {
                $value = is_object($previous) ? array_values(get_object_vars($previous))[0] ?? null : null;
                $connection->statement(sprintf($restore, is_numeric($value) ? (string) $value : '0'));
            } catch (Throwable) {
                // The connection is unusable; Laravel reconnects on the next statement.
            }
        }
    }

    /**
     * The driver's [set, read previous, restore format] statements, or null when unsupported.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function statements(ConnectionInterface $connection, int $timeoutMs): ?array
    {
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;

        return match ($driver) {
            'mysql' => [sprintf('SET SESSION max_execution_time = %d', $timeoutMs), 'SELECT @@SESSION.max_execution_time AS value', 'SET SESSION max_execution_time = %s'],
            'mariadb' => [sprintf('SET SESSION max_statement_time = %F', $timeoutMs / 1000), 'SELECT @@SESSION.max_statement_time AS value', 'SET SESSION max_statement_time = %s'],
            'pgsql' => [sprintf('SET statement_timeout = %d', $timeoutMs), "SELECT setting::bigint AS value FROM pg_settings WHERE name = 'statement_timeout'", 'SET statement_timeout = %s'],
            default => null,
        };
    }

    private static function isTimeout(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $info = $exception->errorInfo ?? [];

        return $code === '57014' || in_array($info[1] ?? null, [3024, 1969], true) || ($info[0] ?? null) === '57014';
    }
}
