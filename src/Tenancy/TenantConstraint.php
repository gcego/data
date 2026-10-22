<?php

declare(strict_types=1);

namespace Gcego\Data\Tenancy;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\QueryConstraint;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Limits a query to one tenant's rows. Applied before every other constraint.
 */
final readonly class TenantConstraint implements QueryConstraint
{
    public function __construct(
        public string $column,
        public string|int $tenant,
    ) {}

    public function apply(EloquentBuilder|QueryBuilder|Collection $query, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection
    {
        if ($query instanceof Collection) {
            $tenant = (string) $this->tenant;

            return $query->filter(fn (mixed $item): bool => is_scalar($value = data_get($item, $this->column)) && (string) $value === $tenant);
        }

        return $query->where($query instanceof EloquentBuilder ? $query->qualifyColumn($this->column) : $this->column, '=', $this->tenant);
    }
}
