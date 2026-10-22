<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * The trusted column a filter key reads, when it differs from the key:
 *
 *     Filterable::make('subject', [Integer::make(), Column::make('subject_id')])
 *     Filterable::make('topic', [StringType::make(), Column::make('topics.name')])   // Eloquent relation path
 */
final readonly class Column implements FilterOption
{
    private function __construct(public string $name) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $name): self
    {
        return new self(FieldName::assert('Column', $name));
    }
}
