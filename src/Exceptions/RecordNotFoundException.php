<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * No record with the key exists inside the source's permitted scope (404).
 *
 * Records outside the scope and records that do not exist are deliberately
 * indistinguishable. The key is never included in the message.
 */
final class RecordNotFoundException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public static function in(string $source): self
    {
        return new self(sprintf('No record was found in data source "%s".', $source));
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
