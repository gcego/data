<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Closure;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definition\SourceDefinition;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A trusted, mandatory query constraint written by the developer:
 *
 *     new Query(fn (Builder $query) => $query->where('is_published', true)->with('topics'))
 *
 * The callback receives the base query (an Eloquent builder, a query builder or,
 * for collection sources, the collection) after global scopes and before any
 * request input. It is server-side code: it is never normalized, cached or sent
 * to the browser.
 */
final readonly class Query implements QueryConstraint, SourceConfiguration
{
    public function __construct(public Closure|QueryConstraint $constraint) {}

    public function apply(EloquentBuilder|QueryBuilder|Collection $query, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection|null
    {
        if ($this->constraint instanceof QueryConstraint) {
            return $this->constraint->apply($query, $source);
        }

        $result = ($this->constraint)($query, $source);

        return $result instanceof EloquentBuilder || $result instanceof QueryBuilder || $result instanceof Collection ? $result : null;
    }

    /**
     * @return array{constraint: string}
     */
    public function normalize(): array
    {
        return ['constraint' => $this->constraint instanceof Closure ? 'closure' : $this->constraint::class];
    }
}
