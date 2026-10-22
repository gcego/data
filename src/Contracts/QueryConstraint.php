<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Definition\SourceDefinition;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A trusted, mandatory constraint: developer-defined Query nodes, application
 * data scopes (for example tenancy) and request-scoped scopes from QueryContext.
 *
 * Constraints run before any request input is applied. Request filters and
 * search are added inside their own nested AND groups, so they can narrow a
 * constrained query but never widen it.
 */
interface QueryConstraint
{
    /**
     * Builders may be changed in place (return null or the builder); collection
     * constraints must return the filtered collection.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>  $query
     * @return EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>|null
     */
    public function apply(EloquentBuilder|QueryBuilder|Collection $query, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection|null;
}
