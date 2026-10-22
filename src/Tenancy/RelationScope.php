<?php

declare(strict_types=1);

namespace Gcego\Data\Tenancy;

use Closure;
use Gcego\Data\Contracts\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Extends the tenant scope of a call to related records.
 *
 * While a tenant-scoped call runs (run()), every related query gcego/data
 * builds (relation filters, relation searches, eager loads, includes,
 * relation aggregates, has / doesnt_have) is limited to the same tenant when
 * the related model implements BelongsToTenant. The scope is call-local and
 * always restored, so it never leaks into other calls (Octane, queues).
 * Relations an application lazy loads later are outside gcego/data; enable
 * gcego-data.performance.prevent_lazy_loading to catch them.
 */
final class RelationScope
{
    /**
     * @var list<string|int>
     */
    private static array $tenants = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string|int|null $tenant, Closure $callback): mixed
    {
        if ($tenant === null) {
            return $callback();
        }

        self::$tenants[] = $tenant;

        try {
            return $callback();
        } finally {
            array_pop(self::$tenants);
        }
    }

    /**
     * Limit a related query to the current tenant, when its model belongs to tenants.
     *
     * @param  EloquentBuilder<Model>  $related
     */
    public static function apply(EloquentBuilder $related): void
    {
        $tenant = self::$tenants === [] ? null : self::$tenants[array_key_last(self::$tenants)];
        $model = $related->getModel();

        if ($tenant !== null && $model instanceof BelongsToTenant) {
            $related->where($related->qualifyColumn($model->tenantColumn()), '=', $tenant);
        }
    }

    /**
     * A relation constraint that applies the tenant scope, then the given constraint.
     *
     * @param  Closure(mixed): mixed|null  $constraint
     * @return Closure(mixed): void
     */
    public static function wrap(?Closure $constraint = null): Closure
    {
        return static function (mixed $query) use ($constraint): void {
            $builder = $query instanceof EloquentBuilder ? $query : (is_object($query) && method_exists($query, 'getQuery') ? $query->getQuery() : null);

            if ($builder instanceof EloquentBuilder) {
                self::apply($builder);
            }

            if ($constraint !== null) {
                $constraint($query);
            }
        };
    }
}
