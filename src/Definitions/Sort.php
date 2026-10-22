<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Support\FieldName;

/**
 * The default ordering, used when the request sorts by nothing:
 *
 *     Sort::make('created_at', 'desc')
 *     Sort::make(['level' => 'desc', 'name' => 'asc'])
 *
 * The source's unique key is appended (gcego-data.sorting.stable), so pages
 * never overlap or skip records.
 */
final readonly class Sort implements SourceConfiguration
{
    /**
     * @param  list<array{field: string, direction: SortDirection}>  $orders
     */
    private function __construct(public array $orders) {}

    /**
     * @param  string|array<string, string|SortDirection>  $field  A field, or field => direction pairs in order.
     *
     * @throws InvalidSourceException
     */
    public static function make(string|array $field, string|SortDirection $direction = SortDirection::Ascending): self
    {
        $pairs = is_string($field) ? [$field => $direction] : $field;

        if ($pairs === [] || array_is_list($pairs)) {
            throw InvalidSourceException::invalidDefinition('Sort', 'declare a field, or field => direction pairs.');
        }

        $orders = [];

        foreach ($pairs as $name => $order) {
            $orders[] = ['field' => FieldName::assert('Sort', (string) $name), 'direction' => SortDirection::of($order)];
        }

        return new self($orders);
    }

    /**
     * @return array{orders: list<array{field: string, direction: string}>}
     */
    public function normalize(): array
    {
        return ['orders' => array_map(static fn (array $order): array => ['field' => $order['field'], 'direction' => $order['direction']->value], $this->orders)];
    }
}
