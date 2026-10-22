<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * An operator on the existence of related records (Eloquent sources only).
 * The filter's field is a relation path, not a column.
 */
interface RelationOperator extends Operator
{
    /**
     * @param  EloquentBuilder<Model>  $query
     * @param  string  $relation  A relation path ("reports" or "reports.department").
     * @param  Closure(EloquentBuilder<Model>): void|null  $scope  Extra conditions on the last related query.
     */
    public function applyRelation(EloquentBuilder $query, string $relation, ?Closure $scope): void;
}
