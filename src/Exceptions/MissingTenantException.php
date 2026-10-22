<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A tenant-scoped source was used without a current tenant. Rendered as 403:
 * without a tenant no row is permitted.
 */
final class MissingTenantException extends RuntimeException implements DataException, HttpExceptionInterface
{
    public static function for(string $source): self
    {
        return new self(sprintf('Data source "%s" is tenant-scoped and there is no current tenant. Bind a TenantResolver, use QueryContext::forTenant(), or QueryContext::acrossTenants() for trusted cross-tenant code.', $source));
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
