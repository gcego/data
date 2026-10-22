<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A tenant-scoped source was used without a current tenant. Rendered as 403:
 * without a tenant no row is permitted. The public message is generic; the
 * source and the fix are in context() for logs.
 */
final class MissingTenantException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public string $source = '';

    public static function for(string $source): self
    {
        $exception = new self('No tenant context is available for this request.');
        $exception->source = $source;

        return $exception;
    }

    /**
     * @return array{source: string, hint: string}
     */
    public function context(): array
    {
        return [
            'source' => $this->source,
            'hint' => 'Bind a TenantResolver, use QueryContext::forTenant(), or QueryContext::acrossTenants() for trusted cross-tenant code.',
        ];
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
