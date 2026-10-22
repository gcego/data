<?php

declare(strict_types=1);

namespace Gcego\Data\Tenancy;

use Gcego\Data\Contracts\TenantResolver;

/**
 * The default resolver: there is no current tenant. Tenant-scoped sources then
 * refuse to run unless the QueryContext names a tenant or opts out explicitly.
 */
final readonly class NullTenantResolver implements TenantResolver
{
    public function tenant(): string|int|null
    {
        return null;
    }
}
