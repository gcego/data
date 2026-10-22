<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Gcego\Data\Aggregation\AggregateFunction;
use Gcego\Data\Aggregation\AggregateResult;
use Gcego\Data\Aggregation\Aggregation;
use Gcego\Data\Aggregation\TimeBucket;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Contracts\SupportsAggregation;
use Gcego\Data\DataConfig;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\LimitExceededException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Query\QueryPlan;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Support\Compare;
use Gcego\Data\Support\Relations;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Traversable;

/**
 * In-memory items from Items::make(): arrays or objects, read with data_get().
 *
 * Every execution loads all items, then filters, searches, sorts (stable, with
 * the key as tie-breaker) and slices them in PHP. Use it for small, bounded
 * datasets (settings, enums, API responses already fetched); it is not
 * efficient for large tables. The original items are never modified.
 *
 * Comparison: numbers compare numerically, booleans as booleans, everything
 * else as strings (case-sensitive); text operators and search are
 * case-insensitive. Cursor pagination and relationships are not supported.
 * A call loading more than the plan's maxItems fails (LimitExceededException).
 */
final readonly class CollectionProvider implements DataProvider, ResolvesRecords, SupportsAggregation
{
    public function __construct(private DataConfig $config) {}

    public function prepare(SourceDefinition $source): void
    {
        if ($source->items === null) {
            throw InvalidSourceException::invalid($source->name, 'collection sources need items: Items::make(fn () => [...]).');
        }

        if ($source->with !== [] || $source->includables !== [] || $source->aggregates !== [] || Relations::declared($source)) {
            throw InvalidSourceException::invalid($source->name, 'relationships need an Eloquent model source; collection sources cannot eager load, aggregate or filter by relations.');
        }

        if ($source->pagination->mode === PaginationMode::Cursor) {
            throw InvalidSourceException::invalid($source->name, 'collection sources do not support cursor pagination; use Pagination::make() or Pagination::simple().');
        }
    }

    /**
     * @return LengthAwarePaginator<int, mixed>|Paginator<int, mixed>
     */
    public function fetch(SourceDefinition $source, QueryPlan $plan, array $constraints): LengthAwarePaginator|Paginator
    {
        $items = $this->sorted($this->filtered($source, $plan, $constraints), $source, $plan);
        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];
        $offset = ($plan->page - 1) * $plan->perPage;

        return $plan->mode === PaginationMode::Simple
            ? new Paginator($items->slice($offset, $plan->perPage + 1)->values(), $plan->perPage, $plan->page, $options)
            : new LengthAwarePaginator($items->slice($offset, $plan->perPage)->values(), $items->count(), $plan->perPage, $plan->page, $options);
    }

    /**
     * Collections are already in memory: aggregates are computed here, after the same constraints, filters and search.
     */
    public function aggregate(SourceDefinition $source, QueryPlan $plan, array $constraints, Aggregation $aggregation): AggregateResult
    {
        $items = $this->filtered($source, $plan, $constraints);

        if ($aggregation->dateColumn !== null && ($aggregation->from !== null || $aggregation->to !== null)) {
            $column = $aggregation->dateColumn;
            $items = $items->filter(static function (mixed $item) use ($aggregation, $column): bool {
                $date = self::date(data_get($item, $column));

                return $date !== null && ($aggregation->from === null || $date >= $aggregation->from) && ($aggregation->to === null || $date <= $aggregation->to);
            })->values();
        }

        $compute = static function (Collection $group) use ($aggregation): int|float|null {
            if ($aggregation->column === null) {
                return $group->count();
            }

            $values = [];

            foreach ($group as $item) {
                $value = data_get($item, $aggregation->column);

                if (is_int($value) || is_float($value)) {
                    $values[] = $value;
                } elseif (is_string($value) && is_numeric($value)) {
                    $values[] = str_contains($value, '.') ? (float) $value : (int) $value;
                }
            }

            return match ($aggregation->function) {
                AggregateFunction::Count => count($values),
                AggregateFunction::Sum => array_sum($values),
                AggregateFunction::Average => $values === [] ? null : array_sum($values) / count($values),
                AggregateFunction::Minimum => $values === [] ? null : min($values),
                AggregateFunction::Maximum => $values === [] ? null : max($values),
            };
        };

        if (! $aggregation->isGrouped()) {
            $value = $compute($items);

            return new AggregateResult(is_int($value) || is_float($value) ? $value : null);
        }

        $grouped = $items->groupBy(static function (mixed $item) use ($aggregation): string {
            if ($aggregation->groupBy !== null) {
                $key = data_get($item, $aggregation->groupBy);

                return is_scalar($key) ? (string) $key : '';
            }

            $date = self::date(data_get($item, (string) $aggregation->dateColumn));

            return $date === null ? '' : match ($aggregation->bucket) {
                TimeBucket::Week => $date->format('o-\WW'),
                TimeBucket::Month => $date->format('Y-m'),
                TimeBucket::Year => $date->format('Y'),
                default => $date->format('Y-m-d'),
            };
        })->sortKeys();

        $groups = [];

        foreach ($grouped as $key => $group) {
            $value = $compute($group);
            $groups[] = ['key' => (string) $key, 'value' => is_int($value) || is_float($value) ? $value : 0];
        }

        return new AggregateResult(null, array_slice($groups, 0, $aggregation->limit), count($groups) > $aggregation->limit);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return match (true) {
                $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value),
                is_string($value) && $value !== '' => new \DateTimeImmutable($value),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     * @return Collection<int, mixed>
     */
    private function filtered(SourceDefinition $source, QueryPlan $plan, array $constraints): Collection
    {
        $items = $this->constrained($source, $constraints, $plan->maxItems);

        if ($plan->filters !== []) {
            $items = $items->filter(fn (mixed $item): bool => $this->matchesAll($item, $plan->filters));
        }

        foreach ($plan->customFilters as ['filter' => $filter, 'value' => $value]) {
            $result = $filter->apply($items->values(), $value, $source);

            if (! $result instanceof Collection) {
                throw InvalidSourceException::invalid($source->name, sprintf('custom filter "%s" must return the filtered collection.', $filter->key));
            }

            $items = $result;
        }

        $fields = $plan->searchFields ?? $source->search->fields ?? [];

        if ($plan->terms !== [] && $source->search !== null && $fields !== []) {
            $items = $items->filter(static function (mixed $item) use ($fields, $plan): bool {
                foreach ($plan->terms as $term) {
                    $found = false;

                    foreach ($fields as $field) {
                        $value = data_get($item, $field);

                        if ((is_scalar($value) || $value instanceof \Stringable) && mb_stripos((string) $value, $term) !== false) {
                            $found = true;

                            break;
                        }
                    }

                    if (! $found) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $items->values();
    }

    public function find(SourceDefinition $source, string|int $key, array $constraints, array $includes = [], array $aggregates = []): mixed
    {
        $field = $source->key ?? 'id';

        return $this->constrained($source, $constraints, $this->config->collectionMaxItems)->first(static fn (mixed $item): bool => Compare::values(data_get($item, $field), $key) === 0 && data_get($item, $field) !== null);
    }

    /**
     * @param  list<QueryConstraint>  $constraints
     * @return Collection<int, mixed>
     */
    private function constrained(SourceDefinition $source, array $constraints, ?int $maxItems = null): Collection
    {
        $raw = ($source->items ?? static fn (): array => [])();

        if (! is_iterable($raw)) {
            throw InvalidSourceException::invalid($source->name, 'the items factory must return an array or a collection.');
        }

        $items = new Collection($raw instanceof Traversable ? iterator_to_array($raw, false) : array_values($raw));
        $maxItems ??= $source->limits->maxItems ?? $this->config->collectionMaxItems;

        if ($items->count() > $maxItems) {
            throw LimitExceededException::collectionItems($source->name, $maxItems);
        }

        foreach ($constraints as $constraint) {
            $result = $constraint->apply($items, $source);

            if (! $result instanceof Collection) {
                throw InvalidSourceException::invalid($source->name, sprintf('constraint %s must return the filtered collection.', $constraint::class));
            }

            $items = $result->values();
        }

        return $source->filters === [] ? $items : $items->filter(fn (mixed $item): bool => $this->matchesAll($item, $source->filters))->values();
    }

    /**
     * @param  list<Filter>  $filters
     */
    private function matchesAll(mixed $item, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! self::matches(data_get($item, $filter->field), $filter)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(mixed $actual, Filter $filter): bool
    {
        return $filter->operator->matches($actual, $filter->value, $filter->type);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    private function sorted(Collection $items, SourceDefinition $source, QueryPlan $plan): Collection
    {
        $sorts = $plan->sorts;
        $key = $source->key ?? 'id';

        if ($this->config->stableOrdering && ! in_array($key, array_column($sorts, 'column'), true)) {
            $sorts[] = ['column' => $key, 'direction' => SortDirection::Ascending];
        }

        if ($sorts === []) {
            return $items;
        }

        $all = $items->all();

        // usort is stable since PHP 8.0: equal items keep their original order.
        usort($all, static function (mixed $a, mixed $b) use ($sorts): int {
            foreach ($sorts as ['column' => $column, 'direction' => $direction]) {
                $result = Compare::values(data_get($a, $column), data_get($b, $column));

                if ($result !== 0) {
                    return $direction === SortDirection::Descending ? -$result : $result;
                }
            }

            return 0;
        });

        return new Collection($all);
    }
}
