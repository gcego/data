<?php

declare(strict_types=1);

namespace Gcego\Data\Tenancy;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\BelongsToTenant;
use Gcego\Data\Contracts\TenantResolver;
use Gcego\Data\Exceptions\MissingTenantException;
use Gcego\Data\Query\QueryContext;
use Illuminate\Contracts\Container\Container;
use WeakMap;

/**
 * Enforces tenant scoping for reads, writes and cache isolation.
 *
 * A source is tenant-scoped when it declares TenantScoped or its model
 * implements BelongsToTenant. The tenant is QueryContext::$tenant when set,
 * otherwise the bound TenantResolver's (resolved per call, so a scoped
 * binding is honoured). It fails closed.
 */
final class TenantGuard
{
    /**
     * @var WeakMap<SourceDefinition, string|false>
     */
    private WeakMap $columns;

    public function __construct(private readonly Container $container)
    {
        $this->columns = new WeakMap;
    }

    /**
     * The tenant column of a source, or null when it is not tenant-scoped.
     */
    public function column(SourceDefinition $source): ?string
    {
        $column = $this->columns[$source] ??= $this->detect($source) ?? false;

        return $column === false ? null : $column;
    }

    /**
     * The tenant of a call, whether or not the source is scoped.
     */
    public function tenant(QueryContext $context): string|int|null
    {
        if ($context->tenant !== null) {
            return $context->tenant;
        }

        return $this->container->bound(TenantResolver::class) ? $this->container->make(TenantResolver::class)->tenant() : null;
    }

    /**
     * The tenant constraint of a call, null for unscoped sources or acrossTenants() contexts.
     *
     * @throws MissingTenantException
     */
    public function constraint(SourceDefinition $source, QueryContext $context): ?TenantConstraint
    {
        $column = $this->column($source);

        if ($column === null || $context->acrossTenants) {
            return null;
        }

        return new TenantConstraint($column, $this->tenant($context) ?? throw MissingTenantException::for($source->name));
    }

    private function detect(SourceDefinition $source): ?string
    {
        if ($source->tenant !== null) {
            return $source->tenant->column;
        }

        if ($source->model !== null && is_subclass_of($source->model, BelongsToTenant::class)) {
            /** @var BelongsToTenant $model */
            $model = new ($source->model);

            return $model->tenantColumn();
        }

        return null;
    }
}
