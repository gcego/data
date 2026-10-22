<?php

declare(strict_types=1);

namespace Gcego\Data\Search;

use Gcego\Data\Contracts\SearchStrategy;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * The fields a request search term is matched against:
 *
 *     new Search(['name', 'code'])
 *     new Search('name', 'code', 'topics.name')   // Eloquent: also whereHas('topics')
 *     (new Search('title', 'body'))->using(FullTextSearch::class)
 *
 * Every term must match at least one field (terms AND, fields OR). Only
 * these trusted fields are searched; the request only supplies the term.
 * Immutable: using() returns a copy.
 */
final class Search implements SourceConfiguration
{
    /**
     * @var list<string>
     */
    public readonly array $fields;

    /**
     * @var class-string<SearchStrategy>|null
     */
    private ?string $strategy = null;

    /**
     * @param  string|list<string>  ...$fields
     *
     * @throws InvalidSourceException
     */
    public function __construct(string|array ...$fields)
    {
        $flat = [];

        foreach ($fields as $field) {
            foreach ((array) $field as $item) {
                if (! is_string($item)) {
                    throw InvalidSourceException::invalidDefinition('Search', 'fields must be strings.');
                }

                $flat[] = FieldName::assert('Search', $item);
            }
        }

        if ($flat === []) {
            throw InvalidSourceException::invalidDefinition('Search', 'declare at least one field.');
        }

        $this->fields = array_values(array_unique($flat));
    }

    /**
     * @param  class-string<SearchStrategy>  $strategy  Resolved from the container per query.
     *
     * @throws InvalidSourceException
     */
    public function using(string $strategy): self
    {
        if (! is_a($strategy, SearchStrategy::class, true)) {
            throw InvalidSourceException::invalidDefinition('Search', sprintf('%s does not implement %s.', $strategy, SearchStrategy::class));
        }

        $copy = clone $this;
        $copy->strategy = $strategy;

        return $copy;
    }

    /**
     * @return class-string<SearchStrategy>|null Null means LikeSearch.
     */
    public function strategy(): ?string
    {
        return $this->strategy;
    }

    /**
     * @return array{fields: list<string>, strategy: string|null}
     */
    public function normalize(): array
    {
        return ['fields' => $this->fields, 'strategy' => $this->strategy];
    }
}
