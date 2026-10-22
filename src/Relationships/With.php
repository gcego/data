<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Eloquent relationships that are always eager loaded (one query per relation, never N+1):
 *
 *     new With('topics', 'topics.lessons')
 *     new With(['topics' => fn ($query) => $query->where('is_published', true)])
 *
 * Constraint callbacks are trusted code and receive the relation query.
 * Relation names are checked against the model when the source is compiled.
 */
final readonly class With implements SourceConfiguration
{
    /**
     * @var array<string, Closure|null> relation path => constraint
     */
    public array $relations;

    /**
     * @param  string|array<string, Closure|null>|list<string>  ...$relations
     *
     * @throws InvalidSourceException
     */
    public function __construct(string|array ...$relations)
    {
        $this->relations = RelationPaths::collect('With', $relations);
    }

    /**
     * @return array{relations: list<string>}
     */
    public function normalize(): array
    {
        return ['relations' => array_keys($this->relations)];
    }
}
