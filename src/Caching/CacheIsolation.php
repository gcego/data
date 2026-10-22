<?php

declare(strict_types=1);

namespace Gcego\Data\Caching;

/**
 * Who may share a cached result. Authorization, record policies and field
 * rules are always applied to every caller, cached or not; isolation decides
 * whose query results are reused.
 */
enum CacheIsolation: string
{
    /** One entry per user and tenant (the default; always safe). */
    case User = 'user';

    /** One entry per tenant, shared by its users. Use when results do not depend on the user. */
    case Tenant = 'tenant';

    /** One entry for everyone. Only for data that is identical for every user and tenant. */
    case Shared = 'shared';
}
