<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Definition\SourceDefinition;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Applies a validated search term to a builder. Replace the default
 * LikeSearch with a full-text or search-engine strategy per source:
 *
 *     (new Search('title', 'body'))->using(FullTextSearch::class)
 *
 * The strategy is called inside a nested where group that is AND-ed with the
 * mandatory constraints, so it can only narrow results.
 */
interface SearchStrategy
{
    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query  The nested group to add conditions to.
     * @param  list<string>  $fields  Trusted columns or relation paths from the Search definition.
     * @param  list<string>  $terms  Non-empty terms, already length-checked.
     */
    public function apply(EloquentBuilder|QueryBuilder $query, array $fields, array $terms, SourceDefinition $source): void;
}
