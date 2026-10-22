<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\CustomFilter;
use Gcego\Data\Filters\Filterable;

/**
 * The filters a request may apply (?filter[key][operator]=value):
 *
 *     Filterables::make([
 *         Filterable::make('age', [Integer::make(), GreaterThanOrEqual::make(), LessThan::make()]),
 *         Filterable::make('status', [Enum::make(Status::class), In::make()]),
 *         CustomFilter::make('overdue', fn ($query, bool $overdue) => ..., [Boolean::make()]),
 *     ])
 *
 * Keys are unique. Requests naming any other key or operator are rejected.
 */
final readonly class Filterables implements SourceConfiguration
{
    /**
     * @param  array<string, Filterable>  $filterables
     * @param  array<string, CustomFilter>  $custom
     */
    private function __construct(public array $filterables, public array $custom) {}

    /**
     * @param  list<Filterable|CustomFilter>  $filters
     *
     * @throws InvalidSourceException
     */
    public static function make(array $filters): self
    {
        if ($filters === [] || ! array_is_list($filters)) {
            throw InvalidSourceException::invalidDefinition('Filterables', 'declare a non-empty list of Filterable::make() and CustomFilter::make().');
        }

        $filterables = [];
        $custom = [];

        foreach ($filters as $filter) {
            if (! $filter instanceof Filterable && ! $filter instanceof CustomFilter) {
                throw InvalidSourceException::invalidDefinition('Filterables', sprintf('%s is not a Filterable or a CustomFilter.', get_debug_type($filter)));
            }

            if (isset($filterables[$filter->key]) || isset($custom[$filter->key])) {
                throw InvalidSourceException::invalidDefinition('Filterables', sprintf('the filter key "%s" is declared twice.', $filter->key));
            }

            $filter instanceof Filterable ? $filterables[$filter->key] = $filter : $custom[$filter->key] = $filter;
        }

        return new self($filterables, $custom);
    }

    /**
     * @return array{filterables: list<array<string, mixed>>, custom: list<array<string, mixed>>}
     */
    public function normalize(): array
    {
        return [
            'filterables' => array_values(array_map(static fn (Filterable $filter): array => $filter->normalize(), $this->filterables)),
            'custom' => array_values(array_map(static fn (CustomFilter $filter): array => $filter->normalize(), $this->custom)),
        ];
    }
}
