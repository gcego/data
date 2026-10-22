<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\MutationException;
use Gcego\Data\Support\Relations;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Plain query builders: DatabaseTable::make('report_rows') or a trusted
 * BuilderFactory::make(fn () => DB::table(...)->join(...)), called per execution.
 *
 * Rows are returned as associative arrays. There are no models, so no global
 * scopes, casts, $hidden or relationships: select only the columns you mean
 * to expose (Columns::make([...]) or select() in the factory). Fields are used as
 * written ("name" or "subjects.name"); the key defaults to "id".
 *
 * Table sources (DatabaseTable) are writable: inserts, updates and deletes
 * by key, with no model events. Builder factories (joins) are read-only.
 */
final class QueryBuilderProvider extends BuilderProvider
{
    protected function validate(SourceDefinition $source): void
    {
        if ($source->table === null && $source->builder === null) {
            throw InvalidSourceException::invalid($source->name, 'query-builder sources need a table or a builder factory.');
        }

        if ($source->with !== [] || $source->includables !== [] || $source->aggregates !== [] || Relations::declared($source)) {
            throw InvalidSourceException::invalid($source->name, 'relationships need an Eloquent model source; query-builder sources cannot eager load, aggregate or filter by relations.');
        }
    }

    protected function newQuery(SourceDefinition $source): QueryBuilder
    {
        if ($source->builder !== null) {
            $query = ($source->builder)();

            return $query instanceof QueryBuilder
                ? $query
                : throw InvalidSourceException::invalid($source->name, sprintf('the builder factory must return %s%s.', QueryBuilder::class, $query instanceof EloquentBuilder ? '; for Eloquent builders use Model::make(...) with Constraints::make([Query::make(...)])' : ''));
        }

        return $this->container->make(ConnectionResolverInterface::class)->connection($source->connection)->table((string) $source->table);
    }

    protected function keyColumn(EloquentBuilder|QueryBuilder $query, SourceDefinition $source): string
    {
        return $source->key ?? 'id';
    }

    protected function normalizeKey(SourceDefinition $source, string|int $key): string|int|null
    {
        return is_int($key) || ($key !== '' && strlen($key) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $key) !== 1) ? $key : null;
    }

    protected function item(mixed $row): mixed
    {
        return is_object($row) ? get_object_vars($row) : $row;
    }

    public function writable(SourceDefinition $source): bool
    {
        return $source->table !== null && $source->builder === null;
    }

    public function connection(SourceDefinition $source): ConnectionInterface
    {
        return $this->container->make(ConnectionResolverInterface::class)->connection($source->connection);
    }

    public function create(SourceDefinition $source, array $attributes): mixed
    {
        $key = $source->key ?? 'id';
        $table = $this->table($source);

        if (array_key_exists($key, $attributes)) {
            $table->insert($attributes);
            $id = $attributes[$key];
        } else {
            $id = $table->insertGetId($attributes, $key);
        }

        return $this->row($source, is_int($id) || is_string($id) ? $id : throw MutationException::unsupported($source->name, 'the inserted row has no usable key.'));
    }

    public function update(SourceDefinition $source, mixed $record, array $attributes): mixed
    {
        $key = $this->keyOf($source, $record) ?? throw MutationException::unsupported($source->name, 'the record has no key.');

        if ($attributes !== []) {
            $this->table($source)->where($source->key ?? 'id', '=', $key)->update($attributes);
        }

        return $this->row($source, $key);
    }

    public function delete(SourceDefinition $source, mixed $record, bool $force = false): void
    {
        $key = $this->keyOf($source, $record) ?? throw MutationException::unsupported($source->name, 'the record has no key.');
        $this->table($source)->where($source->key ?? 'id', '=', $key)->delete();
    }

    public function restore(SourceDefinition $source, mixed $record): mixed
    {
        throw MutationException::unsupported($source->name, 'only Eloquent models with SoftDeletes can be restored.');
    }

    public function keyOf(SourceDefinition $source, mixed $record): string|int|null
    {
        $key = data_get($record, $source->key ?? 'id');

        return is_int($key) || is_string($key) ? $key : null;
    }

    public function attributesOf(mixed $record): array
    {
        $attributes = is_array($record) ? $record : (is_object($record) ? get_object_vars($record) : []);

        return array_filter($attributes, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private function table(SourceDefinition $source): QueryBuilder
    {
        return $this->writable($source)
            ? $this->connection($source)->table((string) $source->table)
            : throw MutationException::unsupported($source->name, 'builder factory sources are read-only; write through a table or model source.');
    }

    private function row(SourceDefinition $source, string|int $key): mixed
    {
        $row = $this->table($source)->where($source->key ?? 'id', '=', $key)->first($this->columns($source));

        return $row === null ? null : $this->item($row);
    }
}
