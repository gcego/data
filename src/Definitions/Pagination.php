<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Pagination\PaginationMode;

/**
 * How results are paged:
 *
 *     Pagination::make(25)                 // page numbers with a total (one COUNT query)
 *     Pagination::simple(50)               // page numbers, no total
 *     Pagination::cursor(50, max: 100)     // keyset pagination over a deterministic order
 *
 * Requests may choose a page size (?per_page=) up to $max and never above
 * gcego-data.pagination.max_per_page.
 */
final readonly class Pagination implements SourceConfiguration
{
    private function __construct(
        public ?int $perPage,
        public PaginationMode $mode,
        public ?int $max,
    ) {
        if (($perPage !== null && $perPage < 1) || ($max !== null && $max < 1) || ($perPage !== null && $max !== null && $max < $perPage)) {
            throw InvalidSourceException::invalidDefinition('Pagination', 'page sizes must be positive, and max must not be smaller than the page size.');
        }
    }

    /**
     * @throws InvalidSourceException
     */
    public static function make(?int $perPage = null, ?int $max = null): self
    {
        return new self($perPage, PaginationMode::Pages, $max);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function simple(?int $perPage = null, ?int $max = null): self
    {
        return new self($perPage, PaginationMode::Simple, $max);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function cursor(?int $perPage = null, ?int $max = null): self
    {
        return new self($perPage, PaginationMode::Cursor, $max);
    }

    /**
     * @return array{perPage: int|null, mode: string, max: int|null}
     */
    public function normalize(): array
    {
        return ['perPage' => $this->perPage, 'mode' => $this->mode->value, 'max' => $this->max];
    }
}
