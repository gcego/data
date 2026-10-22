<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Relationships\RelationAggregate;

/**
 * Relationship counts and aggregates added to every record by the database (Eloquent):
 *
 *     Aggregates::make([
 *         RelationAggregate::count('reports'),
 *         RelationAggregate::sum('payments', 'amount')->optional(),   // only with ?include=payments_sum_amount
 *     ])
 */
final readonly class Aggregates implements SourceConfiguration
{
    /**
     * @param  array<string, RelationAggregate>  $aggregates  By alias.
     */
    private function __construct(public array $aggregates) {}

    /**
     * @param  list<RelationAggregate>  $aggregates
     *
     * @throws InvalidSourceException
     */
    public static function make(array $aggregates): self
    {
        if ($aggregates === [] || ! array_is_list($aggregates)) {
            throw InvalidSourceException::invalidDefinition('Aggregates', 'declare a non-empty list of RelationAggregate definitions.');
        }

        $byAlias = [];

        foreach ($aggregates as $aggregate) {
            if (! $aggregate instanceof RelationAggregate) {
                throw InvalidSourceException::invalidDefinition('Aggregates', sprintf('%s is not a RelationAggregate.', get_debug_type($aggregate)));
            }

            $byAlias[$aggregate->alias] = isset($byAlias[$aggregate->alias])
                ? throw InvalidSourceException::invalidDefinition('Aggregates', sprintf('the alias "%s" is declared twice.', $aggregate->alias))
                : $aggregate;
        }

        return new self($byAlias);
    }

    /**
     * @return array{aggregates: list<array<string, mixed>>}
     */
    public function normalize(): array
    {
        return ['aggregates' => array_values(array_map(static fn (RelationAggregate $aggregate): array => $aggregate->normalize(), $this->aggregates))];
    }
}
