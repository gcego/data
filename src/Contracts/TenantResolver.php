<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * The tenant the current request or job acts for (a school, hospital,
 * company, agency or store), provided by the application or a tenancy package:
 *
 *     $this->app->scoped(TenantResolver::class, CurrentInstitution::class);
 *
 * gcego/data never decides who the tenant is; it only enforces the scope.
 * Bound to NullTenantResolver (no tenant) by default, so tenant-scoped sources
 * fail closed until a resolver is bound. Bind it scoped (per request) under
 * Octane and queue workers.
 */
interface TenantResolver
{
    /**
     * The current tenant key, or null when there is none.
     */
    public function tenant(): string|int|null;
}
