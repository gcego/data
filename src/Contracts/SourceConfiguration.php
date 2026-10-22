<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * A configuration node accepted by Source (Filter, Sort, Paginate, Query, ...).
 *
 * Packages may implement it for their own source options; the compiler
 * keeps unknown implementations in SourceDefinition::$extensions.
 */
interface SourceConfiguration
{
    /**
     * A plain description for diagnostics and caching. It must never contain
     * closures, credentials or other server-side secrets.
     *
     * @return array<string, mixed>
     */
    public function normalize(): array;
}
