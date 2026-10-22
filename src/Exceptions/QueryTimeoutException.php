<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A read exceeded its statement timeout (Limits::make(timeoutMs: ...) or
 * gcego-data.performance.timeout_ms). Rendered as 503 with a generic message;
 * the source, the timeout and the database error are in context() and
 * getPrevious() for logs, never in the response.
 */
final class QueryTimeoutException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public string $source = '';

    public int $timeoutMs = 0;

    public static function after(string $source, int $timeoutMs, ?Throwable $previous = null): self
    {
        $exception = new self('The request took too long. Narrow the filters and try again.', 0, $previous);
        $exception->source = $source;
        $exception->timeoutMs = $timeoutMs;

        return $exception;
    }

    /**
     * @return array{source: string, timeout_ms: int}
     */
    public function context(): array
    {
        return ['source' => $this->source, 'timeout_ms' => $this->timeoutMs];
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
