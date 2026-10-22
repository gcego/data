<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\SearchStrategy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A custom strategy: prefix matching (index-friendly).
 */
final class StartsWithSearch implements SearchStrategy
{
    public function apply(EloquentBuilder|QueryBuilder $query, array $fields, array $terms, SourceDefinition $source): void
    {
        foreach ($terms as $term) {
            $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($fields, $term): void {
                foreach ($fields as $field) {
                    $group->orWhere($field, 'like', $term.'%');
                }
            });
        }
    }
}
