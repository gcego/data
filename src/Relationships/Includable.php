<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Eloquent relationships a request may ask to eager load (include=topics,topics.lessons):
 *
 *     new Includable('topics', 'topics.lessons')
 *
 * Only these exact paths are accepted. Requested paths are loaded with eager
 * loading, and each relation's own Eloquent scopes still apply.
 */
final readonly class Includable implements SourceConfiguration
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
        $this->relations = RelationPaths::collect('Includable', $relations);
    }

    /**
     * @return array{relations: list<string>}
     */
    public function normalize(): array
    {
        return ['relations' => array_keys($this->relations)];
    }
}
