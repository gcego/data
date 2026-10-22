<?php

declare(strict_types=1);

namespace Gcego\Data\Sorting;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Support\FieldName;

/**
 * Allows request input to sort by these keys:
 *
 *     new Sortable('name', 'code', 'created_at')
 *     new Sortable('name', ['newest' => 'created_at'])   // key => column
 *     Sortable::map(['newest' => 'created_at'])
 *
 * The request names a key (sort=-newest); the column comes only from here.
 * Sorting is limited to the source's own columns.
 */
final readonly class Sortable implements SourceConfiguration
{
    /**
     * @var array<string, string> key => column
     */
    public array $columns;

    /**
     * @param  string|array<string, string>  ...$keys  Keys sorted by the column of the same name, or key => column maps.
     *
     * @throws InvalidSourceException
     */
    public function __construct(string|array ...$keys)
    {
        $columns = [];

        foreach ($keys as $key) {
            $columns += is_string($key) ? [$key => $key] : $key;
        }

        $this->columns = self::validate($columns);
    }

    /**
     * @param  array<string, string>  $columns  key => column
     */
    public static function map(array $columns): self
    {
        return new self($columns);
    }

    /**
     * @return array{columns: array<string, string>}
     */
    public function normalize(): array
    {
        return ['columns' => $this->columns];
    }

    /**
     * @param  array<mixed>  $columns
     * @return array<string, string>
     */
    private static function validate(array $columns): array
    {
        if ($columns === []) {
            throw InvalidSourceException::invalidDefinition('Sortable', 'declare at least one key.');
        }

        foreach ($columns as $key => $column) {
            if (! is_string($key) || preg_match(Filterable::KeyPattern, $key) !== 1 || ! is_string($column) || str_contains(FieldName::assert('Sortable', $column), '.')) {
                throw InvalidSourceException::invalidDefinition('Sortable', sprintf('"%s" must map a lowercase key to a column of the source itself (no relation paths).', is_string($key) ? mb_substr($key, 0, 64) : (string) $key));
            }
        }

        /** @var array<string, string> $columns */
        return $columns;
    }
}
