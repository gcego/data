<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Query\Query;

/**
 * Trusted, mandatory conditions written by the developer:
 *
 *     Constraints::make([
 *         Filter::make('is_active', true),
 *         Query::make(fn (Builder $query) => $query->whereNull('archived_at')),
 *         new ActiveEmployeesScope,                       // any QueryConstraint
 *     ])
 *
 * Request input can only narrow the result inside them, never override or
 * remove them. Tenant isolation does not rely on them: it is enforced by
 * TenantScoped / BelongsToTenant before any constraint.
 */
final readonly class Constraints implements SourceConfiguration
{
    /**
     * @param  list<Filter>  $filters
     * @param  list<QueryConstraint>  $queries
     */
    private function __construct(public array $filters, public array $queries) {}

    /**
     * @param  list<Filter|QueryConstraint>  $constraints
     *
     * @throws InvalidSourceException
     */
    public static function make(array $constraints): self
    {
        if ($constraints === [] || ! array_is_list($constraints)) {
            throw InvalidSourceException::invalidDefinition('Constraints', 'declare a non-empty list of Filter::make() and Query::make() conditions.');
        }

        $filters = [];
        $queries = [];

        foreach ($constraints as $constraint) {
            match (true) {
                $constraint instanceof Filter => $filters[] = $constraint,
                $constraint instanceof QueryConstraint => $queries[] = $constraint,
                default => throw InvalidSourceException::invalidDefinition('Constraints', sprintf('%s is not a Filter or a QueryConstraint.', get_debug_type($constraint))),
            };
        }

        return new self($filters, $queries);
    }

    /**
     * @return array{filters: list<array<string, mixed>>, queries: list<string>}
     */
    public function normalize(): array
    {
        return [
            'filters' => array_map(static fn (Filter $filter): array => $filter->normalize(), $this->filters),
            'queries' => array_map(static fn (QueryConstraint $query): string => $query instanceof Query ? $query->normalize()['constraint'] : $query::class, $this->queries),
        ];
    }
}
