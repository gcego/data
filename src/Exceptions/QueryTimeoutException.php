<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A read exceeded its statement timeout (Limits::$timeoutMs or
 * gcego-data.performance.timeout_ms). Rendered as 503 Service Unavailable.
 */
final class QueryTimeoutException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public static function after(string $source, int $timeoutMs, ?Throwable $previous = null): self
    {
        return new self(sprintf('Data source "%s" exceeded its %d ms statement timeout. Narrow the request or add indexes.', $source, $timeoutMs), 0, $previous);
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
