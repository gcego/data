<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * A database table read through Laravel's query builder (not a UI table):
 *
 *     Source::define(DatabaseTable::make('report_rows'), Columns::make(['id', 'total']))
 *
 * Rows are associative arrays: there are no models, so no casts, $hidden or
 * relationships. Table sources are writable when they declare Writable.
 */
final readonly class DatabaseTable implements SourceConfiguration
{
    private function __construct(public string $name) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $name): self
    {
        $name = FieldName::assert('DatabaseTable', $name);

        return substr_count($name, '.') <= 1 ? new self($name) : throw InvalidSourceException::invalidDefinition('DatabaseTable', 'use "table" or "schema.table".');
    }

    /**
     * @return array{table: string}
     */
    public function normalize(): array
    {
        return ['table' => $this->name];
    }
}
