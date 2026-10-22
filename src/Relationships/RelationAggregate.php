<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Closure;
use Gcego\Data\Aggregation\AggregateFunction;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * An aggregate of an Eloquent relationship, computed by the database as a
 * subquery of the listing (one query, never N+1) and added to every record:
 *
 *     RelationAggregate::count('topics')                         // topics_count
 *     RelationAggregate::count('topics', 'published_topics', fn ($query) => $query->where('is_published', true))
 *     RelationAggregate::sum('enrolments', 'fee')                // enrolments_sum_fee
 *     RelationAggregate::exists('topics')                        // topics_exists
 *     RelationAggregate::count('lessons')->optional()            // only with ?include=lessons_count
 *
 * The alias may be used by Sortable (new Sortable(['topics' => 'topics_count'])).
 * Optional aggregates are computed only when the request includes their alias.
 */
final readonly class RelationAggregate implements SourceConfiguration
{
    public const string Exists = 'exists';

    public string $alias;

    /**
     * @throws InvalidSourceException
     */
    private function __construct(
        public string $relation,
        public AggregateFunction|string $function,
        public ?string $column,
        ?string $alias,
        public ?Closure $constraint,
        public bool $optional = false,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $relation) !== 1) {
            throw InvalidSourceException::invalidDefinition('RelationAggregate', sprintf('"%s" must be the name of a direct relationship.', mb_substr($relation, 0, 64)));
        }

        if ($column !== null) {
            FieldName::assert('RelationAggregate', $column);
        }

        $name = $function instanceof AggregateFunction ? $function->value : $function;
        $this->alias = $alias ?? match (true) {
            $function === AggregateFunction::Count => $relation.'_count',
            $function === self::Exists => $relation.'_exists',
            default => sprintf('%s_%s_%s', $relation, $name, (string) $column),
        };

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $this->alias) !== 1) {
            throw InvalidSourceException::invalidDefinition('RelationAggregate', sprintf('the alias "%s" must use lowercase letters, digits and "_".', mb_substr($this->alias, 0, 64)));
        }
    }

    /**
     * @throws InvalidSourceException
     */
    public static function count(string $relation, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, AggregateFunction::Count, null, $alias, $constraint);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function sum(string $relation, string $column, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, AggregateFunction::Sum, $column, $alias, $constraint);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function avg(string $relation, string $column, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, AggregateFunction::Average, $column, $alias, $constraint);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function min(string $relation, string $column, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, AggregateFunction::Minimum, $column, $alias, $constraint);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function max(string $relation, string $column, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, AggregateFunction::Maximum, $column, $alias, $constraint);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function exists(string $relation, ?string $alias = null, ?Closure $constraint = null): self
    {
        return new self($relation, self::Exists, null, $alias, $constraint);
    }

    /**
     * A copy computed only when the request includes the alias (include=topics_count).
     */
    public function optional(): self
    {
        return new self($this->relation, $this->function, $this->column, $this->alias, $this->constraint, true);
    }

    /**
     * The Eloquent aggregate function name: count, sum, avg, min, max or exists.
     */
    public function eloquentFunction(): string
    {
        return $this->function instanceof AggregateFunction ? $this->function->value : self::Exists;
    }

    /**
     * @return array{relation: string, function: string, column: string|null, alias: string, optional: bool, constrained: bool}
     */
    public function normalize(): array
    {
        return [
            'relation' => $this->relation,
            'function' => $this->eloquentFunction(),
            'column' => $this->column,
            'alias' => $this->alias,
            'optional' => $this->optional,
            'constrained' => $this->constraint !== null,
        ];
    }
}
