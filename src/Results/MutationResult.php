<?php

declare(strict_types=1);

namespace Gcego\Data\Results;

use Gcego\Data\Security\DataOperation;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The outcome of one write.
 *
 * $record is the record after the write, redacted by field rules and passed
 * through Transform nodes like any read (null after a delete). $changes are
 * the attributes the write set; $original their previous values (updates).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MutationResult implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $original
     */
    public function __construct(
        public string $source,
        public DataOperation $operation,
        public string|int|null $key,
        public mixed $record,
        public array $changes = [],
        public array $original = [],
        public float $durationMs = 0.0,
    ) {}

    /**
     * Whether an update changed anything (always true for other operations).
     */
    public function changed(): bool
    {
        return $this->operation !== DataOperation::Update || $this->changes !== [];
    }

    /**
     * @return array{operation: string, key: string|int|null, data: mixed}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'key' => $this->key,
            'data' => $this->record instanceof Arrayable ? $this->record->toArray() : $this->record,
        ];
    }

    /**
     * @return array{operation: string, key: string|int|null, data: mixed}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
