<?php

declare(strict_types=1);

namespace Gcego\Data\Results;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of records.
 *
 * - items(): the records: Eloquent models (Eloquent sources), associative
 *   arrays (query-builder sources) or the original items (collection sources),
 *   after Transform nodes.
 * - paginator(): the Laravel paginator, for links() and API resources. It holds
 *   the transformed items, except for cursor pagination, where it keeps the
 *   original records because Laravel computes cursors from them; use
 *   $pagination->nextCursor/previousCursor there.
 * - toArray()/JSON: models through their own toArray(), so $hidden and $visible apply.
 *
 * @implements IteratorAggregate<int, mixed>
 * @implements Arrayable<string, mixed>
 */
final readonly class DataResult implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<mixed>  $items
     * @param  AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>  $paginator
     * @param  array<string, list<string>>  $applied  Request keys that took effect: filter, sort, include; plus search.
     */
    public function __construct(
        public string $source,
        public string $provider,
        private array $items,
        public PaginationResult $pagination,
        private AbstractPaginator|AbstractCursorPaginator $paginator,
        public array $applied = [],
        public float $durationMs = 0.0,
    ) {}

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        return new Collection($this->items);
    }

    /**
     * @return AbstractPaginator<array-key, mixed>|AbstractCursorPaginator<array-key, mixed>
     */
    public function paginator(): AbstractPaginator|AbstractCursorPaginator
    {
        return $this->paginator;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array{data: list<mixed>, pagination: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item, $this->items),
            'pagination' => $this->pagination->toArray(),
        ];
    }

    /**
     * @return array{data: list<mixed>, pagination: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
