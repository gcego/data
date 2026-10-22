<?php

declare(strict_types=1);

namespace Gcego\Data\Aggregation;

/**
 * Date grouping for aggregates. Buckets are computed by the database in the
 * stored (application) time zone; keys are "2026-03-01", "2026-W09", "2026-03", "2026".
 */
enum TimeBucket: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
