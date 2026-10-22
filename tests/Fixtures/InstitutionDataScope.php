<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\QueryConstraint;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A data-layer tenant scope, as a school-administration application would register it.
 */
final readonly class InstitutionDataScope implements QueryConstraint
{
    public function __construct(private int $institution) {}

    public function apply(EloquentBuilder|QueryBuilder|Collection $query, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection
    {
        if ($query instanceof Collection) {
            return $query->where('institution_id', $this->institution);
        }

        $column = $query instanceof EloquentBuilder ? $query->qualifyColumn('institution_id') : 'institution_id';

        return $query->where($column, $this->institution);
    }
}
