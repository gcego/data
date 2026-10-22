<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A read completed (dispatched only when gcego-data.events.reads is on), for
 * auditing who read which source. It carries no record data.
 */
final readonly class SourceQueried
{
    /**
     * @param  'query'|'find'|'aggregate'  $operation
     * @param  array<string, list<string>>  $applied  Request keys that took effect.
     */
    public function __construct(
        public string $source,
        public string $operation,
        public ?Authenticatable $user,
        public string|int|null $tenant,
        public int $count,
        public float $durationMs,
        public array $applied = [],
        public ?string $cache = null,
    ) {}
}
