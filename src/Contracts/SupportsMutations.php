<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Compilation\SourceDefinition;
use Illuminate\Database\ConnectionInterface;

/**
 * Capability: create, update and delete records. Implemented by the Eloquent
 * provider and by the query-builder provider for table sources.
 *
 * MutationPipeline calls these inside a transaction, after authorization,
 * validation and field checks, with attributes that are already allowlisted,
 * validated and tenant-stamped. Providers write exactly what they receive.
 */
interface SupportsMutations
{
    /**
     * Whether this provider can write this source (for example, not a query-builder factory with joins).
     */
    public function writable(SourceDefinition $source): bool;

    /**
     * The connection writes to this source run on, for the transaction.
     */
    public function connection(SourceDefinition $source): ConnectionInterface;

    /**
     * The record with this key inside the constraints, locked for update, or null.
     *
     * @param  list<QueryConstraint>  $constraints
     */
    public function lock(SourceDefinition $source, string|int $key, array $constraints, bool $trashed = false): mixed;

    /**
     * @param  array<string, mixed>  $attributes
     * @return mixed The created record.
     */
    public function create(SourceDefinition $source, array $attributes): mixed;

    /**
     * @param  array<string, mixed>  $attributes
     * @return mixed The updated record.
     */
    public function update(SourceDefinition $source, mixed $record, array $attributes): mixed;

    public function delete(SourceDefinition $source, mixed $record, bool $force = false): void;

    /**
     * @return mixed The restored record.
     */
    public function restore(SourceDefinition $source, mixed $record): mixed;

    /**
     * The key of a record of this source.
     */
    public function keyOf(SourceDefinition $source, mixed $record): string|int|null;

    /**
     * Attribute values of a record, for change tracking.
     *
     * @return array<string, mixed>
     */
    public function attributesOf(mixed $record): array;
}
