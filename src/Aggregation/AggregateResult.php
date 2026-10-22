<?php

declare(strict_types=1);

namespace Gcego\Data\Aggregation;

/**
 * An aggregate value, or groups ordered by key (time buckets chronologically).
 */
final readonly class AggregateResult
{
    /**
     * @param  list<array{key: string, value: int|float}>  $groups
     */
    public function __construct(
        public int|float|null $value = null,
        public array $groups = [],
        public bool $truncated = false,
    ) {}

    /**
     * @return array<string, int|float> key => value
     */
    public function pairs(): array
    {
        $pairs = [];

        foreach ($this->groups as $group) {
            $pairs[$group['key']] = $group['value'];
        }

        return $pairs;
    }
}
