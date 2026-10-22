<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Compilation\SourceDefinition;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The trusted logic of a CustomFilter (filter[overdue]=1).
 *
 * It receives the request value already validated and cast. Builders are
 * changed in place (return null or the same builder, inside the request
 * filters' own AND group); collection sources must return the filtered
 * collection.
 */
interface FilterHandler
{
    /**
     * @param  EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>  $query
     * @param  scalar|list<scalar>  $value
     * @return EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>|null
     */
    public function apply(EloquentBuilder|QueryBuilder|Collection $query, mixed $value, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection|null;
}
