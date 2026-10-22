<?php

declare(strict_types=1);

namespace Gcego\Data\Performance;

use Closure;
use Gcego\Data\DataConfig;
use Gcego\Data\Events\SlowQueryDetected;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Measures gcego/data calls and, when asked, records the statements they run.
 *
 * One QueryExecuted listener is registered on first use; it records only
 * while a profiled call is active (frames nest, so a call inside another is
 * attributed to both). Slow calls (gcego-data.performance.slow_query_ms) are
 * logged as warnings and dispatch SlowQueryDetected, without bindings.
 */
final class Profiler
{
    /**
     * @var list<list<array{sql: string, time_ms: float, connection: string}>>
     */
    private array $frames = [];

    private bool $listening = false;

    public function __construct(
        private readonly DataConfig $config,
        private readonly Dispatcher $events,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return array{0: T, 1: Profile|null, 2: float} The result, the profile (when profiling) and the duration in ms.
     */
    public function measure(string $source, string $operation, bool $profile, Closure $callback): array
    {
        $profile = $profile || $this->config->profile;

        if ($profile) {
            $this->listen();
            $this->frames[] = [];
        }

        $started = hrtime(true);

        try {
            $result = $callback();
        } finally {
            $duration = round((hrtime(true) - $started) / 1e6, 3);
            $statements = $profile ? (array_pop($this->frames) ?? []) : [];

            if ($profile) {
                foreach (array_keys($this->frames) as $index) {
                    $this->frames[$index] = [...$this->frames[$index], ...$statements];
                }
            }
        }

        $report = $profile ? new Profile($statements, $duration, $this->config->duplicateQueryThreshold) : null;
        $this->reportSlow($source, $operation, $duration, $report);

        return [$result, $report, $duration];
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;
        $this->events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if ($this->frames === []) {
                return;
            }

            $this->frames[array_key_last($this->frames)][] = ['sql' => $query->sql, 'time_ms' => round((float) $query->time, 3), 'connection' => (string) $query->connectionName];
        });
    }

    private function reportSlow(string $source, string $operation, float $duration, ?Profile $profile): void
    {
        $threshold = $this->config->slowQueryMs;

        if ($threshold === null || $duration < $threshold) {
            return;
        }

        $context = ['source' => $source, 'operation' => $operation, 'duration_ms' => $duration, 'threshold_ms' => $threshold, 'queries' => $profile?->count()];

        try {
            $this->logger?->warning(sprintf('gcego/data: slow %s on source "%s" (%.1f ms).', $operation, $source, $duration), $context);
            $this->events->dispatch(new SlowQueryDetected($source, $operation, $duration, $threshold, $profile));
        } catch (Throwable) {
            // Reporting must never break a read.
        }
    }
}
