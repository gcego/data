<?php

declare(strict_types=1);

namespace Gcego\Data\Results;

use Gcego\Data\Exceptions\RecordNotFoundException;

/**
 * The outcome of resolving one record by key: found (inside the permitted scope) or missing.
 */
final readonly class RecordResult
{
    public function __construct(
        public string $source,
        public string|int $key,
        private mixed $record,
        public ?string $cache = null,
        public float $durationMs = 0.0,
    ) {}

    public function found(): bool
    {
        return $this->record !== null;
    }

    /**
     * The record (after Transform nodes), or null.
     */
    public function record(): mixed
    {
        return $this->record;
    }

    /**
     * @throws RecordNotFoundException
     */
    public function orFail(): mixed
    {
        return $this->record ?? throw RecordNotFoundException::in($this->source);
    }
}
