<?php

declare(strict_types=1);

namespace Gcego\Data\Pagination;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * How results are paginated:
 *
 *     new Paginate(25)                 // page numbers with a total
 *     Paginate::simple(50)             // page numbers, no COUNT query
 *     Paginate::cursor(50)             // keyset pagination
 *     new Paginate(25, max: 50)        // requests may ask for up to 50 per page
 *
 * Sources are always paginated: without a Paginate node the configured
 * default page size applies. Requests (?per_page=) may choose a size up to
 * $max, which defaults to and never exceeds gcego-data.pagination.max_per_page;
 * larger requests are capped.
 */
final readonly class Paginate implements SourceConfiguration
{
    /**
     * @throws InvalidSourceException
     */
    public function __construct(
        public ?int $perPage = null,
        public PaginationMode $mode = PaginationMode::Pages,
        public ?int $max = null,
    ) {
        if (($perPage !== null && $perPage < 1) || ($max !== null && $max < 1) || ($perPage !== null && $max !== null && $max < $perPage)) {
            throw InvalidSourceException::invalidDefinition('Paginate', 'page sizes must be positive, and max must not be smaller than the page size.');
        }
    }

    public static function simple(?int $perPage = null, ?int $max = null): self
    {
        return new self($perPage, PaginationMode::Simple, $max);
    }

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
