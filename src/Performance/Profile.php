<?php

declare(strict_types=1);

namespace Gcego\Data\Performance;

/**
 * The statements one gcego/data call executed: SQL text and timing, never
 * bindings (they may hold personal data).
 *
 * duplicates() lists statements repeated at least the configured threshold
 * (gcego-data.performance.duplicate_query_threshold): the usual sign of an
 * N+1 query, such as a relation loaded per record by a transformer.
 */
final readonly class Profile
{
    /**
     * @param  list<array{sql: string, time_ms: float, connection: string}>  $statements
     */
    public function __construct(
        public array $statements,
        public float $durationMs,
        public int $duplicateThreshold = 5,
    ) {}

    public function count(): int
    {
        return count($this->statements);
    }

    public function queryTimeMs(): float
    {
        return round(array_sum(array_column($this->statements, 'time_ms')), 3);
    }

    /**
     * Statements executed at least the duplicate threshold, with their counts.
     *
     * @return array<string, int>
     */
    public function duplicates(): array
    {
        $counts = array_count_values(array_column($this->statements, 'sql'));

        return array_filter($counts, fn (int $count): bool => $count >= $this->duplicateThreshold);
    }

    public function suspectsNPlusOne(): bool
    {
        return $this->duplicates() !== [];
    }

    /**
     * @return array{queries: int, duration_ms: float, query_time_ms: float, duplicates: array<string, int>, statements: list<array{sql: string, time_ms: float, connection: string}>}
     */
    public function toArray(): array
    {
        return [
            'queries' => $this->count(),
            'duration_ms' => $this->durationMs,
            'query_time_ms' => $this->queryTimeMs(),
            'duplicates' => $this->duplicates(),
            'statements' => $this->statements,
        ];
    }
}
