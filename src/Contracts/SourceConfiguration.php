<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * A top-level source definition, passed to Source::define(): Name, Model,
 * Filterables, Search, Sort, Pagination, ... (Gcego\Data\Definitions).
 *
 * Other packages implement it for their own source options; each class may
 * appear once per source, and the compiler keeps unknown implementations in
 * SourceDefinition::$extensions. Implementations must be immutable.
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
