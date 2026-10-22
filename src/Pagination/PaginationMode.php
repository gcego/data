<?php

declare(strict_types=1);

namespace Gcego\Data\Pagination;

/**
 * Pages: page numbers with a total count (one extra COUNT query).
 * Simple: page numbers without a total (no COUNT; "has more" only).
 * Cursor: keyset pagination over a deterministic order; no offsets, no COUNT.
 */
enum PaginationMode: string
{
    case Pages = 'pages';
    case Simple = 'simple';
    case Cursor = 'cursor';
}
