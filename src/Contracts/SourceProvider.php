<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Source;

/**
 * A class that declares a source:
 *
 *     final class EmployeesSource implements SourceProvider
 *     {
 *         public function source(): Source
 *         {
 *             return Source::define(Name::make('employees'), Model::make(Employee::class), Pagination::make(25));
 *         }
 *     }
 *
 *     Data::query(EmployeesSource::class, $request);
 *
 * Resolved through Laravel's container (constructor injection works) for
 * every call. source() must be pure: no queries, no I/O, and no per-request
 * state that should instead be a QueryContext constraint.
 */
interface SourceProvider
{
    public function source(): Source;
}
