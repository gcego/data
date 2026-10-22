<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Gcego\Data\DataConfig;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Exceptions\InvalidSortException;
use Gcego\Data\Contracts\CustomOperator;
use Gcego\Data\Filters\CustomFilter;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Filters\Operators;
use Gcego\Data\Filters\ValueType;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\SortDirection;

/**
 * Turns untrusted QueryInput into a QueryPlan using only the source's allowlists.
 *
 * - Filters: the key must be Filterable, the operator allowed, the value valid
 *   for its type (and options); the column comes from the definition.
 * - Search: trimmed, length- and term-limited.
 * - Sort: keys must be Sortable; defaults apply when none are requested.
 * - Pagination: positive page, page size capped by the source and the configuration.
 * - Includes: exact allowlisted relation paths and optional relation aggregate aliases.
 * - Limits: offset pagination stops at the configured maximum offset.
 *
 * Unknown keys are rejected in strict mode (the default) and ignored otherwise.
 * Invalid values are always rejected.
 */
final readonly class QueryResolver
{
    public function __construct(private DataConfig $config) {}

    /**
     * @throws InvalidQueryException
     */
    public function resolve(SourceDefinition $source, QueryInput $input): QueryPlan
    {
        [$filters, $customFilters, $appliedFilters] = $this->filters($source, $input);
        [$includes, $aggregates] = $this->includes($source, $input);
        [$sorts, $appliedSorts] = $this->sorts($source, $input);
        $pagination = $source->pagination;
        $limit = min($pagination->max ?? $this->config->maxPerPage, $this->config->maxPerPage);
        $perPage = min($input->perPage ?? $pagination->perPage ?? $this->config->defaultPerPage, $limit);

        if ($input->cursor !== null && $pagination->mode !== PaginationMode::Cursor) {
            throw InvalidQueryException::invalid('cursor', 'is not supported by this source; use page.');
        }

        $page = $input->page ?? 1;

        if ($page > intdiv(PHP_INT_MAX, $perPage) - 1) {
            throw InvalidQueryException::invalid('page', 'is out of range.');
        }

        $maxOffset = $source->limits->maxOffset ?? $this->config->maxOffset;

        if ($pagination->mode !== PaginationMode::Cursor && $maxOffset !== null && ($page - 1) * $perPage > $maxOffset) {
            throw InvalidQueryException::invalid('page', sprintf('is beyond the deepest page this source serves (offset %d); narrow the results with filters or search.', $maxOffset));
        }

        // Sorting by an optional aggregate computes it.
        foreach ($sorts as ['column' => $column]) {
            $aggregate = $source->aggregates[$column] ?? null;

            if ($aggregate !== null && ! in_array($aggregate, $aggregates, true)) {
                $aggregates[] = $aggregate;
            }
        }

        return new QueryPlan(
            filters: $filters,
            terms: $this->terms($source, $input),
            sorts: $sorts,
            includes: $includes,
            mode: $pagination->mode,
            perPage: $perPage,
            page: $pagination->mode === PaginationMode::Cursor ? 1 : $page,
            cursor: $input->cursor,
            applied: ['filter' => $appliedFilters, 'sort' => $appliedSorts, 'include' => [...array_keys($includes), ...array_values(array_map(static fn (RelationAggregate $aggregate): string => $aggregate->alias, array_filter($aggregates, static fn (RelationAggregate $aggregate): bool => $aggregate->optional)))]],
            customFilters: $customFilters,
            aggregates: $aggregates,
            timeoutMs: $source->limits->timeoutMs ?? $this->config->timeoutMs,
            maxItems: $source->limits->maxItems ?? $this->config->collectionMaxItems,
        );
    }

    /**
     * @return array{0: list<Filter>, 1: list<array{filter: CustomFilter, value: scalar|list<scalar>}>, 2: list<string>}
     */
    private function filters(SourceDefinition $source, QueryInput $input): array
    {
        $filters = [];
        $custom = [];
        $applied = [];

        foreach ($input->filters as $key => $operators) {
            $filterable = $source->filterables[$key] ?? null;
            $customFilter = $source->customFilters[$key] ?? null;

            if ($filterable === null && $customFilter === null) {
                if ($this->config->strict) {
                    throw InvalidFilterException::unknownField($key, [...array_keys($source->filterables), ...array_keys($source->customFilters)]);
                }

                continue;
            }

            foreach ($operators as $name => $value) {
                if ($customFilter !== null) {
                    if (! in_array($name, $customFilter->operatorNames(), true)) {
                        throw InvalidFilterException::unsupportedOperator($key, $name, $customFilter->operatorNames());
                    }

                    if ($value === '' || $value === null || $value === []) {
                        continue;
                    }

                    $operator = $name === 'in' ? FilterOperator::In : FilterOperator::Equals;
                    $custom[] = ['filter' => $customFilter, 'value' => $this->cast($key, $customFilter->type, $customFilter->options, $operator, $value)];
                    $applied[] = $key;

                    continue;
                }

                /** @var Filterable $filterable */
                $operator = $filterable->operator($name) ?? throw InvalidFilterException::unsupportedOperator($key, $name, $filterable->operatorNames());

                // An empty value (?filter[status]=) means "no filter", as forms submit it for "All".
                if (($value === '' || $value === null || $value === []) && $operator->arity() !== 0) {
                    continue;
                }

                $filters[] = new Filter($filterable->column, $this->cast($key, $filterable->type, $filterable->options, $operator, $value), $operator);
                $applied[] = $key;
            }
        }

        return [$filters, $custom, array_values(array_unique($applied))];
    }

    /**
     * @return string|int|float|bool|list<string|int|float|bool>|null
     *
     * @param  list<string|int|float|bool>  $options
     */
    private function cast(string $key, ValueType $type, array $options, FilterOperator|CustomOperator $operator, mixed $value): string|int|float|bool|array|null
    {

        if ($operator->arity() === 0) {
            return in_array($value, [null, '', true, 1, '1', 'true'], true)
                ? null
                : throw InvalidFilterException::invalidValue($key, sprintf('%s takes no value (use 1 or true).', Operators::name($operator)));
        }

        if (! is_array($value) && ! is_scalar($value)) {
            throw InvalidFilterException::invalidValue($key, 'must be a value or a list of values.');
        }

        $values = is_array($value) ? $value : ($operator->arity() === 1 ? [$value] : explode(',', (string) $value));

        if ($operator->arity() === 1 && count($values) !== 1) {
            throw InvalidFilterException::invalidValue($key, sprintf('%s takes a single value.', Operators::name($operator)));
        }

        if ($operator->arity() === 2 && count($values) !== 2) {
            throw InvalidFilterException::invalidValue($key, sprintf('%s takes exactly two values.', Operators::name($operator)));
        }

        if ($values === [] || count($values) > $this->config->filterMaxValues) {
            throw InvalidFilterException::invalidValue($key, sprintf('takes between 1 and %d values.', $this->config->filterMaxValues));
        }

        $cast = [];

        foreach ($values as $item) {
            $item = $type->cast($key, $item);

            if ($options !== [] && ! in_array($item, $options, true)) {
                throw InvalidFilterException::invalidValue($key, 'is not one of the allowed options.');
            }

            $cast[] = $item;
        }

        return $operator->arity() === 1 ? $cast[0] : $cast;
    }

    /**
     * @return list<string>
     */
    private function terms(SourceDefinition $source, QueryInput $input): array
    {
        if ($input->search === null) {
            return [];
        }

        if ($source->search === null) {
            if ($this->config->strict) {
                throw InvalidQueryException::invalid('search', 'is not supported by this source.');
            }

            return [];
        }

        if (mb_strlen($input->search) > $this->config->searchMaxLength) {
            throw InvalidQueryException::tooLong('search', $this->config->searchMaxLength);
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input->search) === 1) {
            throw InvalidQueryException::invalid('search', 'contains control characters.');
        }

        $terms = array_values(array_unique(preg_split('/\s+/u', $input->search, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        if (count($terms) > $this->config->searchMaxTerms) {
            throw InvalidQueryException::invalid('search', sprintf('has more than %d words.', $this->config->searchMaxTerms));
        }

        return $terms;
    }

    /**
     * @return array{0: list<array{column: string, direction: SortDirection}>, 1: list<string>}
     */
    private function sorts(SourceDefinition $source, QueryInput $input): array
    {
        if (count($input->sorts) > $this->config->sortMaxFields) {
            throw InvalidSortException::tooMany($this->config->sortMaxFields);
        }

        $sorts = [];
        $applied = [];

        foreach ($input->sorts as ['key' => $key, 'direction' => $direction]) {
            $column = $source->sortables[$key] ?? null;

            if ($column === null) {
                if ($this->config->strict) {
                    throw InvalidSortException::unknownField($key, array_keys($source->sortables));
                }

                continue;
            }

            $sorts[$column] ??= ['column' => $column, 'direction' => $direction];
            $applied[] = $key;
        }

        if ($sorts === []) {
            foreach ($source->sorts as $sort) {
                /** @var Sort $sort */
                $sorts[$sort->field] ??= ['column' => $sort->field, 'direction' => $sort->direction];
            }
        }

        return [array_values($sorts), $applied];
    }

    /**
     * @return array{0: array<string, \Closure|null>, 1: list<RelationAggregate>}
     */
    private function includes(SourceDefinition $source, QueryInput $input): array
    {
        $includes = [];
        $aggregates = $source->defaultAggregates();

        foreach ($input->includes as $include) {
            $aggregate = $source->aggregates[$include] ?? null;

            if ($aggregate !== null && $aggregate->optional) {
                $aggregates[] = $aggregate;

                continue;
            }

            if (! array_key_exists($include, $source->includables)) {
                if ($this->config->strict) {
                    $optional = array_keys(array_filter($source->aggregates, static fn (RelationAggregate $aggregate): bool => $aggregate->optional));

                    throw InvalidQueryException::unknownInclude($include, [...array_keys($source->includables), ...$optional]);
                }

                continue;
            }

            $includes[$include] = $source->includables[$include];
        }

        return [$includes, $aggregates];
    }
}
