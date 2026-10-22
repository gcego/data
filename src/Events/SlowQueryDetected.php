<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

use Gcego\Data\Performance\Profile;

/**
 * A gcego/data call took longer than gcego-data.performance.slow_query_ms.
 */
final readonly class SlowQueryDetected
{
    public function __construct(
        public string $source,
        public string $operation,
        public float $durationMs,
        public int $thresholdMs,
        public ?Profile $profile = null,
    ) {}
}
