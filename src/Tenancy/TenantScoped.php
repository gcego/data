<?php

declare(strict_types=1);

namespace Gcego\Data\Tenancy;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * Declares that a source's rows belong to tenants:
 *
 *     new Source(Invoice::class, new TenantScoped('company_id'))
 *     Source::table('report_rows', new TenantScoped('institution_id'))
 *
 * Every read is limited to the current tenant (TenantResolver or
 * QueryContext::forTenant()); every create stamps the column with it, and the
 * column can never be written from input. Without a tenant the source refuses
 * to run (fail closed) unless the context uses acrossTenants().
 */
final readonly class TenantScoped implements SourceConfiguration
{
    /**
     * @throws InvalidSourceException
     */
    public function __construct(public string $column = 'tenant_id')
    {
        FieldName::assert('TenantScoped', $column);
    }

    /**
     * @return array{column: string}
     */
    public function normalize(): array
    {
        return ['column' => $this->column];
    }
}
