<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SearchStrategy;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Relationships\RelationPaths;
use Gcego\Data\Support\FieldName;

/**
 * The fields a request search term (?search=...) is matched against:
 *
 *     Search::make(['name', 'email', 'department.name'])
 *     Search::make(['title', 'body'], strategy: FullTextSearch::class)
 *
 * Every term must match at least one field (terms AND, fields OR). Relation
 * paths (Eloquent) are limited to RelationPaths::MaxDepth relations. The
 * request only supplies the term; at most 20 fields.
 */
final readonly class Search implements SourceConfiguration
{
    public const int MaxFields = 20;

    /**
     * @param  list<string>  $fields
     * @param  class-string<SearchStrategy>|null  $strategy
     */
    private function __construct(public array $fields, public ?string $strategy) {}

    /**
     * @param  list<string>  $fields
     * @param  class-string<SearchStrategy>|null  $strategy  Resolved from the container per query; null means LikeSearch.
     *
     * @throws InvalidSourceException
     */
    public static function make(array $fields, ?string $strategy = null): self
    {
        if ($fields === [] || ! array_is_list($fields) || count($fields) > self::MaxFields) {
            throw InvalidSourceException::invalidDefinition('Search', sprintf('declare 1 to %d field names.', self::MaxFields));
        }

        foreach ($fields as $field) {
            if (! is_string($field)) {
                throw InvalidSourceException::invalidDefinition('Search', 'fields are strings.');
            }

            RelationPaths::assertDepth('Search', FieldName::assert('Search', $field), column: true);
        }

        if ($strategy !== null && ! is_a($strategy, SearchStrategy::class, true)) {
            throw InvalidSourceException::invalidDefinition('Search', sprintf('%s does not implement %s.', $strategy, SearchStrategy::class));
        }

        return new self(array_values(array_unique($fields)), $strategy);
    }

    /**
     * @return array{fields: list<string>, strategy: string|null}
     */
    public function normalize(): array
    {
        return ['fields' => $this->fields, 'strategy' => $this->strategy];
    }
}
