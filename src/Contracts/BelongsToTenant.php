<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * Implemented by Eloquent models whose rows belong to one tenant. Every source
 * of such a model is tenant-scoped automatically, as if it declared
 * new TenantScoped($model->tenantColumn()).
 */
interface BelongsToTenant
{
    /**
     * The column holding the tenant key ("institution_id", "hospital_id").
     */
    public function tenantColumn(): string;
}
