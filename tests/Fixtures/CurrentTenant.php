<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures;

use Gcego\Data\Contracts\TenantResolver;

/**
 * The tenant of the current request, as a tenancy package would resolve it.
 */
final class CurrentTenant implements TenantResolver
{
    public string|int|null $tenant = null;

    public function tenant(): string|int|null
    {
        return $this->tenant;
    }
}
