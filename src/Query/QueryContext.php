<?php

declare(strict_types=1);

namespace Gcego\Data\Query;

use Closure;
use Gcego\Data\Contracts\QueryConstraint;
use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Trusted, per-call execution context, created by server code (never from request input).
 *
 *     QueryContext::for($user)->constrain(new InstitutionDataScope($institution))
 *     QueryContext::system()->forTenant($hospitalId)
 *
 * - $user: who is reading or writing. Null uses Laravel's current user (Gate default).
 * - $constraints: request-scoped mandatory constraints, applied after the
 *   tenant scope, the source's own and the application's registered scopes.
 * - $authorize: false only for trusted system code (jobs, console); see system().
 * - $tenant: the tenant to act for, overriding the TenantResolver.
 * - $acrossTenants: skip tenant scoping (trusted cross-tenant code only).
 * - $cacheScope: identifies the context constraints for result caching;
 *   without it, calls with context constraints are never cached.
 * - $profile: record executed statements in the result (see Performance\Profile).
 *
 * It is a value object passed down a single call; nothing is stored in shared services.
 */
final readonly class QueryContext
{
    /**
     * @param  list<QueryConstraint>  $constraints
     * @param  array<string, scalar|null>  $metadata  Caller information for custom providers and authorizers.
     */
    public function __construct(
        public ?Authenticatable $user = null,
        public array $constraints = [],
        public bool $authorize = true,
        public array $metadata = [],
        public string|int|null $tenant = null,
        public bool $acrossTenants = false,
        public ?string $cacheScope = null,
        public bool $profile = false,
        public bool $cache = true,
    ) {}

    public static function for(?Authenticatable $user): self
    {
        return new self($user);
    }

    /**
     * Skips authorization. Tenant scoping, mandatory constraints and scopes still apply.
     * Use only in code without an end user, such as queued jobs and console commands.
     */
    public static function system(): self
    {
        return new self(authorize: false);
    }

    public function constrain(QueryConstraint|Closure ...$constraints): self
    {
        $added = array_map(static fn (QueryConstraint|Closure $constraint): QueryConstraint => $constraint instanceof Closure ? new Query($constraint) : $constraint, array_values($constraints));

        return $this->with(constraints: [...$this->constraints, ...$added]);
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->with(metadata: [...$this->metadata, ...$metadata]);
    }

    /**
     * Act for this tenant instead of the TenantResolver's.
     */
    public function forTenant(string|int $tenant): self
    {
        return $this->with(tenant: $tenant, acrossTenants: false);
    }

    /**
     * Skip tenant scoping. Only for trusted code that must see every tenant
     * (platform administration, reporting jobs). Creating records in a
     * tenant-scoped source still requires a tenant.
     */
    public function acrossTenants(): self
    {
        return $this->with(acrossTenants: true);
    }

    /**
     * Name the context constraints for result caching, e.g. "department:12".
     * Only use a value that fully identifies every constraint of this context.
     *
     * @throws InvalidSourceException
     */
    public function cacheScope(string $scope): self
    {
        if ($scope === '' || strlen($scope) > 200) {
            throw InvalidSourceException::invalidDefinition('QueryContext', 'the cache scope must be 1 to 200 characters.');
        }

        return $this->with(cacheScope: $scope);
    }

    /**
     * Read fresh data, bypassing (but refreshing) the result cache.
     */
    public function withoutCache(): self
    {
        return $this->with(cache: false);
    }

    /**
     * Record every executed statement (SQL and timing, never bindings) in the result.
     */
    public function profiled(): self
    {
        return $this->with(profile: true);
    }

    /**
     * @param  list<QueryConstraint>|null  $constraints
     * @param  array<string, scalar|null>|null  $metadata
     */
    private function with(
        ?array $constraints = null,
        ?array $metadata = null,
        string|int|null $tenant = null,
        ?bool $acrossTenants = null,
        ?string $cacheScope = null,
        ?bool $profile = null,
        ?bool $cache = null,
    ): self {
        return new self(
            user: $this->user,
            constraints: $constraints ?? $this->constraints,
            authorize: $this->authorize,
            metadata: $metadata ?? $this->metadata,
            tenant: $tenant ?? $this->tenant,
            acrossTenants: $acrossTenants ?? $this->acrossTenants,
            cacheScope: $cacheScope ?? $this->cacheScope,
            profile: $profile ?? $this->profile,
            cache: $cache ?? $this->cache,
        );
    }
}
