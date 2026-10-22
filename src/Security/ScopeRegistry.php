<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Query\Query;

/**
 * Application-wide mandatory data scopes.
 *
 *     $data->scope(new InstitutionDataScope, BelongsToInstitution::class); // models implementing an interface
 *     $data->scope(fn (Builder $query) => $query->where('archived', false), Subject::class);
 *
 * A scope registered for a class or interface applies to Eloquent sources whose
 * model is that class, a subclass or an implementation. A scope registered for
 * no target applies to every source, so it must handle every query type
 * (Eloquent builder, query builder and collection).
 *
 * Scopes hold no request state: per-request values (the current tenant) belong
 * in the scope's own collaborators, or in QueryContext::constrain().
 */
final class ScopeRegistry
{
    /**
     * @var list<array{target: class-string|null, constraint: QueryConstraint}>
     */
    private array $scopes = [];

    /**
     * @param  class-string|null  $target
     */
    public function register(QueryConstraint|Closure $constraint, ?string $target = null): void
    {
        $this->scopes[] = ['target' => $target, 'constraint' => $constraint instanceof Closure ? Query::make($constraint) : $constraint];
    }

    /**
     * @return list<QueryConstraint>
     */
    public function for(SourceDefinition $source): array
    {
        $constraints = [];

        foreach ($this->scopes as ['target' => $target, 'constraint' => $constraint]) {
            if ($target === null || ($source->model !== null && is_a($source->model, $target, true))) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    public function count(): int
    {
        return count($this->scopes);
    }
}
