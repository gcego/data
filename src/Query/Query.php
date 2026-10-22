<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\QueryConstraint;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A trusted, mandatory query constraint, listed in Constraints::make([...]):
 *
 *     Query::make(fn (Builder $query) => $query->where('is_active', true))
 *     Query::make(new ActiveEmployeesScope)
 *
 * The callback receives the base query (an Eloquent builder, a query builder
 * or, for collection sources, the collection) after the tenant scope and
 * before any request input. It is application code, never user input: it is
 * not serialized, cached or sent to the browser.
 */
final readonly class Query implements QueryConstraint
{
    private function __construct(public Closure|QueryConstraint $constraint) {}

    public static function make(Closure|QueryConstraint $constraint): self
    {
        return new self($constraint);
    }

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
