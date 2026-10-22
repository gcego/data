<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\Relations;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Plain query builders: Source::table('report_rows') or a trusted
 * Source::builder(fn () => DB::table(...)->join(...)) factory, called per execution.
 *
 * Rows are returned as associative arrays. There are no models, so no global
 * scopes, casts, $hidden or relationships: select only the columns you mean
 * to expose (Source::Columns or select() in the factory). Fields are used as
 * written ("name" or "subjects.name"); the key defaults to "id".
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
                : throw InvalidSourceException::invalid($source->name, sprintf('the builder factory must return %s%s.', QueryBuilder::class, $query instanceof EloquentBuilder ? '; for Eloquent builders use new Source(Model::class) with a Query node' : ''));
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
}
