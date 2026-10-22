<?php

declare(strict_types=1);

namespace Gcego\Data\Caching;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\DataConfig;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Tenancy\TenantGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;
use WeakMap;

/**
 * Result cache of sources that declare Cached.
 *
 * Keys combine the source name and structure, the operation and its plan,
 * the isolation scope (user, tenant, context cache scope) and one version
 * token per dependency: the source's model and every related model its
 * relations, relation filters and searches touch (or its table). Invalidating
 * a dependency replaces its token, so every entry built on it becomes
 * unreachable at once, on every server, and expires by TTL. A lost token is
 * regenerated, which also invalidates.
 *
 * Only plain query results are stored (records before field redaction and
 * transforms), always serialized, so every caller gets its own copy even from
 * stores that keep PHP objects (array, octane); authorization, record
 * policies, field rules and transforms run for every caller. A cache failure
 * never fails a read: it degrades to an uncached call and is logged.
 */
final class QueryCache
{
    public const string Hit = 'hit';

    public const string Miss = 'miss';

    public const string Bypass = 'bypass';

    /**
     * @var WeakMap<SourceDefinition, list<string>>
     */
    private WeakMap $dependencies;

    public function __construct(
        private readonly CacheFactory $caches,
        private readonly DataConfig $config,
        private readonly TenantGuard $tenants,
        private readonly Container $container,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->dependencies = new WeakMap;
    }

    /**
     * Whether a call may use the cache.
     */
    public function applies(SourceDefinition $source, QueryContext $context): bool
    {
        return $this->config->cacheEnabled
            && $source->cache !== null
            && ($context->constraints === [] || $context->cacheScope !== null);
    }

    /**
     * The cached value of a call, or the computed one (stored unless the context bypasses the cache).
     *
     * @param  array<array-key, mixed>  $fingerprint  Everything (besides isolation) that changes the result.
     * @param  Closure(): mixed  $compute
     * @return array{0: mixed, 1: 'hit'|'miss'|'bypass'}
     */
    public function remember(SourceDefinition $source, QueryContext $context, string $operation, array $fingerprint, Closure $compute): array
    {
        if (! $this->applies($source, $context) || $source->cache === null) {
            return [$compute(), self::Bypass];
        }

        try {
            $store = $this->store($source->cache);
            $key = $this->key($source, $context, $operation, $fingerprint, $store);

            if ($context->cache) {
                $cached = self::decode($store->get($key));

                if ($cached !== null) {
                    return [$cached[0], self::Hit];
                }
            }
        } catch (Throwable $exception) {
            $this->report('read', $source, $exception);

            return [$compute(), self::Bypass];
        }

        $value = $this->locked($store, $key, $context, static fn (): mixed => $compute());

        if ($value[1]) {
            return [$value[0], self::Hit];
        }

        try {
            $store->put($key, self::encode($value[0]), $source->cache->ttl);
        } catch (Throwable $exception) {
            $this->report('write', $source, $exception);
        }

        return [$value[0], self::Miss];
    }

    /**
     * Invalidate every cached result that depends on a model class or a "table:connection:name".
     */
    public function invalidate(string ...$dependencies): void
    {
        if (! $this->config->cacheEnabled || $dependencies === []) {
            return;
        }

        try {
            $store = $this->store(null);

            foreach (array_unique($dependencies) as $dependency) {
                $key = $this->versionKey($dependency);

                // Without a token nothing cached depends on it (keys create tokens), so
                // writes to uncached models cost one read, never a write.
                if ($store->get($key) !== null) {
                    $store->forever($key, bin2hex(random_bytes(8)));
                }
            }
        } catch (Throwable $exception) {
            $this->logger?->error('gcego/data: cache invalidation failed; entries expire by TTL.', ['dependencies' => $dependencies, 'exception' => $exception::class]);
        }
    }

    /**
     * Invalidate everything a source's results depend on.
     */
    public function invalidateSource(SourceDefinition $source): void
    {
        $this->invalidate(...$this->dependencies($source));
    }

    /**
     * The dependencies of a source's results.
     *
     * @return list<string>
     */
    public function dependencies(SourceDefinition $source): array
    {
        return $this->dependencies[$source] ??= $this->collectDependencies($source);
    }

    /**
     * @param  array<array-key, mixed>  $fingerprint
     */
    private function key(SourceDefinition $source, QueryContext $context, string $operation, array $fingerprint, Repository $store): string
    {
        /** @var Cached $cached */
        $cached = $source->cache;
        $dependencies = $this->dependencies($source);
        $versions = $this->versions($store, $dependencies);

        $scope = match ($cached->isolation) {
            CacheIsolation::User => ['user' => $this->user($context), 'tenant' => $this->tenant($source, $context)],
            CacheIsolation::Tenant => ['tenant' => $this->tenant($source, $context)],
            // Never shared across tenants: a tenant-scoped source is keyed by tenant whatever its isolation.
            CacheIsolation::Shared => $this->tenants->column($source) === null ? [] : ['tenant' => $this->tenant($source, $context)],
        };

        $payload = (string) json_encode([
            'source' => $source->name,
            'definition' => $source->toArray(),
            'operation' => $operation,
            'fingerprint' => $fingerprint,
            'scope' => $scope,
            'context' => $context->cacheScope,
            'versions' => $versions,
        ], JSON_PARTIAL_OUTPUT_ON_ERROR);

        return sprintf('%s:result:%s:%s', $this->config->cachePrefix, $source->name, hash('sha256', $payload));
    }

    /**
     * @param  list<string>  $dependencies
     * @return array<string, string>
     */
    private function versions(Repository $store, array $dependencies): array
    {
        $versions = [];

        foreach ($dependencies as $dependency) {
            $key = $this->versionKey($dependency);
            $token = $store->get($key);

            if (! is_string($token)) {
                $store->add($key, bin2hex(random_bytes(8)), null);
                $token = $store->get($key);
            }

            $versions[$dependency] = is_string($token) ? $token : 'unversioned';
        }

        return $versions;
    }

    /**
     * Compute under the store's lock when it has locks, so concurrent misses compute once.
     *
     * @param  Closure(): mixed  $compute
     * @return array{0: mixed, 1: bool} The value, and whether another process computed it.
     */
    private function locked(Repository $store, string $key, QueryContext $context, Closure $compute): array
    {
        $provider = $store->getStore();

        if (! $provider instanceof LockProvider || ! $context->cache) {
            return [$compute(), false];
        }

        $seconds = $this->config->cacheLockSeconds;

        try {
            $result = $provider->lock($key.':lock', $seconds)->block($seconds, static function () use ($store, $key, $compute): array {
                $cached = self::decode($store->get($key));

                return $cached !== null ? [$cached[0], true] : [$compute(), false];
            });

            return is_array($result) && array_key_exists(0, $result) && is_bool($result[1] ?? null) ? [$result[0], $result[1]] : [$compute(), false];
        } catch (LockTimeoutException) {
            return [$compute(), false];
        }
    }

    /**
     * @return array{payload: string}
     */
    private static function encode(mixed $value): array
    {
        return ['payload' => serialize($value)];
    }

    /**
     * @return array{0: mixed}|null The stored value, or null when the entry is missing or unreadable.
     */
    private static function decode(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_string($cached['payload'] ?? null)) {
            return null;
        }

        $value = @unserialize($cached['payload']);

        return $value === false && $cached['payload'] !== serialize(false) ? null : [$value];
    }

    private function store(?Cached $cached): Repository
    {
        return $this->caches->store($cached->store ?? $this->config->cacheStore);
    }

    private function versionKey(string $dependency): string
    {
        return sprintf('%s:version:%s', $this->config->cachePrefix, hash('sha256', $dependency));
    }

    private function user(QueryContext $context): string
    {
        $user = $context->user ?? ($this->container->bound(AuthFactory::class) ? $this->container->make(AuthFactory::class)->guard()->user() : null);

        if (! $user instanceof Authenticatable) {
            return $context->authorize ? 'guest' : 'system';
        }

        $identifier = $user->getAuthIdentifier();

        return $user::class.':'.(is_scalar($identifier) ? (string) $identifier : 'unknown');
    }

    private function tenant(SourceDefinition $source, QueryContext $context): string
    {
        if ($context->acrossTenants && $this->tenants->column($source) !== null) {
            return '*';
        }

        $tenant = $this->tenants->tenant($context);

        return $tenant === null ? '-' : (string) $tenant;
    }

    /**
     * @return list<string>
     */
    private function collectDependencies(SourceDefinition $source): array
    {
        if ($source->model === null) {
            return $source->table !== null && $source->builder === null
                ? [sprintf('table:%s:%s', $source->connection ?? 'default', $source->table)]
                : ['source:'.$source->name];
        }

        $paths = [...array_keys($source->with), ...array_keys($source->includables)];

        foreach ($source->aggregates as $aggregate) {
            $paths[] = $aggregate->relation;
        }

        foreach ([...array_map(static fn ($filterable): string => $filterable->column, array_values($source->filterables)), ...array_map(static fn ($filter): string => $filter->field, $source->filters), ...($source->search->fields ?? [])] as $field) {
            $paths[] = $field;
        }

        $models = [$source->model];

        foreach (array_unique($paths) as $path) {
            $models = [...$models, ...self::relatedModels($source->model, $path)];
        }

        return array_values(array_unique($models));
    }

    /**
     * The models along a relation path (segments that are not relations end the walk).
     *
     * @param  class-string<Model>  $model
     * @return list<class-string<Model>>
     */
    private static function relatedModels(string $model, string $path): array
    {
        $models = [];
        $current = new $model;

        foreach (explode('.', $path) as $segment) {
            if (! method_exists($current, $segment)) {
                break;
            }

            $method = new ReflectionMethod($current, $segment);

            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                break;
            }

            try {
                $relation = $current->{$segment}();
            } catch (Throwable) {
                break;
            }

            if (! $relation instanceof Relation) {
                break;
            }

            $current = $relation->getRelated();
            $models[] = $current::class;
        }

        return $models;
    }

    private function report(string $operation, SourceDefinition $source, Throwable $exception): void
    {
        $this->logger?->warning(sprintf('gcego/data: cache %s failed for source "%s"; the call ran uncached.', $operation, $source->name), ['exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
}
