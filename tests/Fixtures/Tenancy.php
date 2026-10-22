<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures;

/**
 * The institution the current request acts for (a request-scoped binding in an application).
 */
final class Tenancy
{
    public ?int $institution = null;
}
