<?php

declare(strict_types=1);

namespace Gcego\Data\Sorting;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * A default ordering, applied when the request asks for no sort:
 *
 *     new Sort('name')            // ascending
 *     new Sort('created_at', 'desc')
 *     Sort::desc('created_at')
 *
 * Several Sort nodes order by several columns, in declaration order. The
 * source's key is always appended as a final tie-breaker, so pagination is stable.
 */
final readonly class Sort implements SourceConfiguration
{
    public SortDirection $direction;

    /**
     * @throws InvalidSourceException
     */
    public function __construct(public string $field, SortDirection|string $direction = SortDirection::Ascending)
    {
        FieldName::assert('Sort', $field);
        $this->direction = SortDirection::of($direction);
    }

    public static function asc(string $field): self
    {
        return new self($field, SortDirection::Ascending);
    }

    public static function desc(string $field): self
    {
        return new self($field, SortDirection::Descending);
    }

    /**
     * @return array{field: string, direction: string}
     */
    public function normalize(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction->value];
    }
}
