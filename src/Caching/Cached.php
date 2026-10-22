<?php

declare(strict_types=1);

namespace Gcego\Data\Caching;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Caches a source's query, record and aggregate results:
 *
 *     Source::model(Subject::class, new Cached(ttl: 300))->named('catalogue')
 *     new Cached(ttl: 3600, isolation: CacheIsolation::Tenant, store: 'redis')
 *
 * Cached sources must be named: the name identifies the source in cache keys.
 * Entries are isolated (see CacheIsolation) and invalidated when the source's
 * model or table changes: through gcego/data mutations, Eloquent model events
 * (gcego-data.cache.invalidate_on_model_events) or Data::invalidate(). The
 * TTL bounds staleness from writes gcego/data cannot see (raw SQL, other apps).
 *
 * Not cached: cursor pages, calls with context constraints but no
 * QueryContext::cacheScope(), and calls made with QueryContext::withoutCache().
 */
final readonly class Cached implements SourceConfiguration
{
    public const int MaxTtl = 604800;

    /**
     * @param  int  $ttl  Seconds, 1 to 604800 (one week).
     * @param  string|null  $store  A cache store name; null uses gcego-data.cache.store or the default store.
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public int $ttl = 300,
        public CacheIsolation $isolation = CacheIsolation::User,
        public ?string $store = null,
    ) {
        if ($ttl < 1 || $ttl > self::MaxTtl) {
            throw InvalidSourceException::invalidDefinition('Cached', sprintf('ttl must be between 1 and %d seconds.', self::MaxTtl));
        }
    }

    /**
     * @return array{ttl: int, isolation: string, store: string|null}
     */
    public function normalize(): array
    {
        return ['ttl' => $this->ttl, 'isolation' => $this->isolation->value, 'store' => $this->store];
    }
}
