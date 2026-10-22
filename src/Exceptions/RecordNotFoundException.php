<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * No record with the key exists inside the source's permitted scope (404).
 *
 * Records outside the scope and records that do not exist are deliberately
 * indistinguishable. The public message names neither the key nor the
 * source; the source is in context() for logs.
 */
final class RecordNotFoundException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public string $source = '';

    public static function in(string $source): self
    {
        $exception = new self('The requested record was not found.');
        $exception->source = $source;

        return $exception;
    }

    /**
     * Log context (Laravel adds it to the exception's log entry).
     *
     * @return array{source: string}
     */
    public function context(): array
    {
        return ['source' => $this->source];
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
