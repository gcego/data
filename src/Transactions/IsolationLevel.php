<?php

declare(strict_types=1);

namespace Gcego\Data\Transactions;

/**
 * SQL transaction isolation levels. Applied on MySQL/MariaDB, PostgreSQL and
 * SQL Server; SQLite transactions are always serializable and ignore it.
 */
enum IsolationLevel: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted = 'READ COMMITTED';
    case RepeatableRead = 'REPEATABLE READ';
    case Serializable = 'SERIALIZABLE';
}
