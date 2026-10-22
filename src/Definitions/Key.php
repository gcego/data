<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * The unique key of a table, builder or collection source (default "id").
 * Model sources always use the model's primary key.
 */
final readonly class Key implements SourceConfiguration
{
    private function __construct(public string $column) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $column): self
    {
        return new self(FieldName::assert('Key', $column));
    }

    /**
     * @return array{key: string}
     */
    public function normalize(): array
    {
        return ['key' => $this->column];
    }
}
