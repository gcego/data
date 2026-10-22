<?php

declare(strict_types=1);

namespace Gcego\Data\Support;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\RelationOperator;

/**
 * @internal Detects relation features that only Eloquent sources support.
 */
final class Relations
{
    /**
     * Whether the source filters by related records (has / doesnt_have).
     */
    public static function declared(SourceDefinition $source): bool
    {
        foreach ($source->filterables as $filterable) {
            if ($filterable->isRelation()) {
                return true;
            }
        }

        foreach ($source->filters as $filter) {
            if ($filter->operator instanceof RelationOperator) {
                return true;
            }
        }

        return false;
    }
}
