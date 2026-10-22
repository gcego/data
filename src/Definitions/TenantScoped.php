<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * Declares that a source's rows belong to tenants:
 *
 *     Source::define(Model::make(Invoice::class), TenantScoped::make('company_id'))
 *     Source::define(DatabaseTable::make('report_rows'), TenantScoped::make('institution_id'))
 *
 * Every read is limited to the current tenant (TenantResolver or
 * QueryContext::forTenant()); every create stamps the column with it, and the
 * column can never be written from input. Without a tenant the source refuses
 * to run (fail closed) unless the context uses acrossTenants().
 */
final readonly class TenantScoped implements SourceConfiguration
{
    private function __construct(public string $column) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $column = 'tenant_id'): self
    {
        return new self(FieldName::assert('TenantScoped', $column));
    }

    /**
     * @return array{column: string}
     */
    public function normalize(): array
    {
        return ['column' => $this->column];
    }
}
