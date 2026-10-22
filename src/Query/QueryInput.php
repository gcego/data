<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Sorting\SortDirection;
use Illuminate\Http\Request;

/**
 * Untrusted query parameters from a request, a Livewire component or an API client.
 *
 *     ?filter[status]=published&filter[score][gte]=50&filter[level][]=1&filter[level][]=2
 *     &search=algebra&sort=-created_at,name&page=2&per_page=50&cursor=...&include=topics
 *
 * This class only checks the shape (strings, lists, positive integers).
 * Keys, operators and values are checked against the source's allowlists by
 * QueryResolver. Input never chooses columns, relations or SQL.
 */
final readonly class QueryInput
{
    public const int MaxCursorLength = 2048;

    /**
     * @param  array<string, array<string, mixed>>  $filters  key => [operator => raw value]
     * @param  list<array{key: string, direction: SortDirection}>  $sorts
     * @param  list<string>  $includes
     */
    public function __construct(
        public array $filters = [],
        public ?string $search = null,
        public array $sorts = [],
        public ?int $page = null,
        public ?int $perPage = null,
        public ?string $cursor = null,
        public array $includes = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<mixed>  $input  Keys: filter, search, sort, page, per_page, cursor, include.
     *
     * @throws InvalidQueryException
     */
    public static function fromArray(array $input): self
    {
        return new self(
            filters: self::filters($input['filter'] ?? []),
            search: self::search($input['search'] ?? null),
            sorts: self::sorts($input['sort'] ?? null),
            page: self::positive('page', $input['page'] ?? null),
            perPage: self::positive('per_page', $input['per_page'] ?? null),
            cursor: self::cursor($input['cursor'] ?? null),
            includes: self::list('include', $input['include'] ?? null),
        );
    }

    /**
     * Reads the query string, or a nested group of it (?subjects[filter][status]=x) when $group is given.
     *
     * @throws InvalidQueryException
     */
    public static function fromRequest(Request $request, ?string $group = null): self
    {
        $input = $group === null ? $request->query() : $request->query($group, []);

        return self::fromArray(is_array($input) ? $input : throw InvalidQueryException::invalid((string) $group, 'must be a group of query parameters.'));
    }

    public function isEmpty(): bool
    {
        return $this == self::none();
    }

    /**
     * The parameters as a query-string array, for links that keep the current state.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'filter' => array_map(static fn (array $operators): array => $operators, $this->filters),
            'search' => $this->search,
            'sort' => $this->sorts === [] ? null : implode(',', array_map(static fn (array $sort): string => ($sort['direction'] === SortDirection::Descending ? '-' : '').$sort['key'], $this->sorts)),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'cursor' => $this->cursor,
            'include' => $this->includes === [] ? null : implode(',', $this->includes),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function filters(mixed $filters): array
    {
        if (! is_array($filters)) {
            throw InvalidQueryException::invalid('filter', 'must be a group of filters, for example filter[status]=published.');
        }

        $parsed = [];

        foreach ($filters as $key => $value) {
            if (! is_string($key)) {
                throw InvalidQueryException::invalid('filter', 'keys must be field names.');
            }

            $parsed[$key] = match (true) {
                is_array($value) && array_is_list($value) => ['in' => self::scalars('filter['.$key.']', $value)],
                is_array($value) => self::operators($key, $value),
                default => ['eq' => self::scalar('filter['.$key.']', $value)],
            };
        }

        return $parsed;
    }

    /**
     * @param  array<array-key, mixed>  $operators
     * @return array<string, mixed>
     */
    private static function operators(string $key, array $operators): array
    {
        $parsed = [];

        foreach ($operators as $operator => $value) {
            if (! is_string($operator)) {
                throw InvalidQueryException::invalid('filter['.$key.']', 'must map operators to values, for example filter[score][gte]=50.');
            }

            $parsed[$operator] = is_array($value) ? self::scalars('filter['.$key.']['.$operator.']', $value) : self::scalar('filter['.$key.']['.$operator.']', $value);
        }

        return $parsed;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string|int|float|bool>
     */
    private static function scalars(string $parameter, array $values): array
    {
        if (! array_is_list($values)) {
            throw InvalidQueryException::invalid($parameter, 'must be a list of values.');
        }

        return array_map(static fn (mixed $value): string|int|float|bool => self::scalar($parameter, $value) ?? throw InvalidQueryException::invalid($parameter, 'cannot contain empty values.'), $values);
    }

    private static function scalar(string $parameter, mixed $value): string|int|float|bool|null
    {
        return $value === null || is_scalar($value) ? $value : throw InvalidQueryException::invalid($parameter, 'must be a single value.');
    }

    private static function search(mixed $search): ?string
    {
        if ($search === null) {
            return null;
        }

        if (! is_string($search) && ! is_int($search)) {
            throw InvalidQueryException::invalid('search', 'must be a string.');
        }

        $search = trim((string) $search);

        return $search === '' ? null : $search;
    }

    /**
     * @return list<array{key: string, direction: SortDirection}>
     */
    private static function sorts(mixed $sort): array
    {
        $sorts = [];

        foreach (self::list('sort', $sort) as $item) {
            $sorts[] = str_starts_with($item, '-')
                ? ['key' => substr($item, 1), 'direction' => SortDirection::Descending]
                : ['key' => ltrim($item, '+'), 'direction' => SortDirection::Ascending];
        }

        return $sorts;
    }

    /**
     * @return list<string>
     */
    private static function list(string $parameter, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_string($value) ? explode(',', $value) : (is_array($value) && array_is_list($value) ? $value : throw InvalidQueryException::invalid($parameter, 'must be a comma-separated string or a list.'));

        if (count($items) > 50) {
            throw InvalidQueryException::invalid($parameter, 'has too many items.');
        }

        $list = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                throw InvalidQueryException::invalid($parameter, 'must contain strings.');
            }

            if (($item = trim($item)) !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    private static function positive(string $parameter, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1)) {
            $value = (int) $value;

            if ($value >= 1) {
                return $value;
            }
        }

        throw InvalidQueryException::invalid($parameter, 'must be a positive integer.');
    }

    private static function cursor(mixed $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! is_string($cursor) || strlen($cursor) > self::MaxCursorLength || preg_match('/^[A-Za-z0-9_\-=]+$/', $cursor) !== 1) {
            throw InvalidQueryException::invalid('cursor', 'is not a valid cursor.');
        }

        return $cursor;
    }
}
