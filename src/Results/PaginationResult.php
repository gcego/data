<?php

declare(strict_types=1);

namespace Gcego\Data\Results;

use Gcego\Data\Pagination\PaginationMode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Provider-independent pagination metadata. Totals exist only in Pages mode.
 */
final readonly class PaginationResult
{
    public function __construct(
        public PaginationMode $mode,
        public int $perPage,
        public int $count,
        public bool $hasMorePages,
        public ?int $currentPage = null,
        public ?int $total = null,
        public ?int $lastPage = null,
        public ?int $from = null,
        public ?int $to = null,
        public ?string $nextCursor = null,
        public ?string $previousCursor = null,
    ) {}

    /**
     * @param  AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>  $paginator
     */
    public static function fromPaginator(AbstractPaginator|AbstractCursorPaginator $paginator): self
    {
        if ($paginator instanceof CursorPaginator) {
            return new self(
                mode: PaginationMode::Cursor,
                perPage: $paginator->perPage(),
                count: $paginator->count(),
                hasMorePages: $paginator->hasMorePages(),
                nextCursor: $paginator->nextCursor()?->encode(),
                previousCursor: $paginator->previousCursor()?->encode(),
            );
        }

        if (! $paginator instanceof Paginator) {
            throw new \LogicException(sprintf('%s does not implement %s.', $paginator::class, Paginator::class));
        }

        $lengthAware = $paginator instanceof LengthAwarePaginator;

        return new self(
            mode: $lengthAware ? PaginationMode::Pages : PaginationMode::Simple,
            perPage: $paginator->perPage(),
            count: $paginator->count(),
            hasMorePages: $paginator->hasMorePages(),
            currentPage: $paginator->currentPage(),
            total: $lengthAware ? $paginator->total() : null,
            lastPage: $lengthAware ? $paginator->lastPage() : null,
            from: $paginator->firstItem(),
            to: $paginator->lastItem(),
        );
    }

    public function isFirstPage(): bool
    {
        return $this->mode === PaginationMode::Cursor ? $this->previousCursor === null : $this->currentPage === 1;
    }

    /**
     * @return array{mode: string, per_page: int, count: int, has_more_pages: bool, current_page: int|null, total: int|null, last_page: int|null, from: int|null, to: int|null, next_cursor: string|null, previous_cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'per_page' => $this->perPage,
            'count' => $this->count,
            'has_more_pages' => $this->hasMorePages,
            'current_page' => $this->currentPage,
            'total' => $this->total,
            'last_page' => $this->lastPage,
            'from' => $this->from,
            'to' => $this->to,
            'next_cursor' => $this->nextCursor,
            'previous_cursor' => $this->previousCursor,
        ];
    }
}
