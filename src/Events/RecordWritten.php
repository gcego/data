<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

use Gcego\Data\Security\DataOperation;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Base of the write events, dispatched after the transaction commits (never
 * for rolled-back writes). Listeners may queue work or write audit trails.
 */
abstract readonly class RecordWritten
{
    /**
     * @param  array<string, mixed>  $changes  Attributes the write set (create, update) or [] (delete).
     * @param  array<string, mixed>  $original  Previous values of the changed attributes (update).
     */
    public function __construct(
        public string $source,
        public DataOperation $operation,
        public string|int|null $key,
        public mixed $record,
        public array $changes,
        public array $original,
        public ?Authenticatable $user,
        public string|int|null $tenant,
    ) {}
}
