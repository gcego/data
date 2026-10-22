<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Execution limits of one source, overriding gcego-data.performance:
 *
 *     Limits::make(timeoutMs: 2000, maxOffset: 10000)
 *     Limits::make(maxItems: 5000)              // collection sources
 *
 * - timeoutMs: statement timeout for reads (MySQL/MariaDB max_execution_time,
 *   PostgreSQL statement_timeout; other drivers ignore it). A timed-out query
 *   fails with QueryTimeoutException (503).
 * - maxOffset: deepest row offset page-number pagination may reach; deeper
 *   pages are rejected (400) in favour of filters or cursor pagination.
 * - maxItems: most items a collection source may load.
 */
final readonly class Limits implements SourceConfiguration
{
    /**
     * @throws InvalidSourceException
     */
    private function __construct(
        public ?int $timeoutMs,
        public ?int $maxOffset,
        public ?int $maxItems,
    ) {
        foreach (['timeoutMs' => $timeoutMs, 'maxOffset' => $maxOffset, 'maxItems' => $maxItems] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw InvalidSourceException::invalidDefinition('Limits', sprintf('%s must be a positive integer.', $name));
            }
        }
    }

    /**
     * @throws InvalidSourceException
     */
    public static function make(?int $timeoutMs = null, ?int $maxOffset = null, ?int $maxItems = null): self
    {
        return new self($timeoutMs, $maxOffset, $maxItems);
    }

    /**
     * @return array{timeout_ms: int|null, max_offset: int|null, max_items: int|null}
     */
    public function normalize(): array
    {
        return ['timeout_ms' => $this->timeoutMs, 'max_offset' => $this->maxOffset, 'max_items' => $this->maxItems];
    }
}
