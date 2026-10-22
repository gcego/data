<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Support\FieldName;

/**
 * The orderings a request may choose (?sort=-created_at,name):
 *
 *     Sortables::make(['name', 'created_at'])
 *     Sortables::make(['name', 'newest' => 'created_at', 'popular' => 'reports_count'])
 *
 * A plain entry allows a column under its own name; key => column exposes a
 * column (or a relation aggregate alias) under another name. Only the
 * source's own columns: relation paths are rejected.
 */
final readonly class Sortables implements SourceConfiguration
{
    /**
     * @param  array<string, string>  $columns  key => column
     */
    private function __construct(public array $columns) {}

    /**
     * @param  array<array-key, string>  $columns
     *
     * @throws InvalidSourceException
     */
    public static function make(array $columns): self
    {
        $map = [];

        foreach ($columns as $key => $column) {
            $key = is_int($key) ? $column : $key;

            if (! is_string($key) || ! is_string($column) || preg_match(Filterable::KeyPattern, $key) !== 1 || str_contains(FieldName::assert('Sortables', $column), '.')) {
                throw InvalidSourceException::invalidDefinition('Sortables', sprintf('"%s" must map a lowercase key to a column of the source itself (no relation paths).', is_string($key) ? mb_substr($key, 0, 64) : (string) $key));
            }

            $map[$key] = isset($map[$key]) ? throw InvalidSourceException::invalidDefinition('Sortables', sprintf('the key "%s" is declared twice.', $key)) : $column;
        }

        if ($map === []) {
            throw InvalidSourceException::invalidDefinition('Sortables', 'declare at least one column.');
        }

        return new self($map);
    }

    /**
     * @return array{columns: array<string, string>}
     */
    public function normalize(): array
    {
        return ['columns' => $this->columns];
    }
}
