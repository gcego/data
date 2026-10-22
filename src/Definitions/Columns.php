<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * The columns a table or builder source selects (all by default). Select only
 * what callers may see: table rows have no $hidden.
 */
final readonly class Columns implements SourceConfiguration
{
    /**
     * @param  list<string>  $names
     */
    private function __construct(public array $names) {}

    /**
     * @param  list<string>  $names
     *
     * @throws InvalidSourceException
     */
    public static function make(array $names): self
    {
        if ($names === [] || ! array_is_list($names)) {
            throw InvalidSourceException::invalidDefinition('Columns', 'declare a non-empty list of column names.');
        }

        return new self(array_values(array_unique(array_map(static fn (mixed $name): string => is_string($name) ? FieldName::assert('Columns', $name) : throw InvalidSourceException::invalidDefinition('Columns', 'column names are strings.'), $names))));
    }

    /**
     * @return array{columns: list<string>}
     */
    public function normalize(): array
    {
        return ['columns' => $this->names];
    }
}
