<?php

declare(strict_types=1);

namespace Gcego\Data\Aggregation;

use DateTimeInterface;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Support\FieldName;

/**
 * A trusted aggregate request, built by server code (never from request input):
 *
 *     new Aggregation(AggregateFunction::Count)
 *     new Aggregation(AggregateFunction::Sum, 'amount', dateColumn: 'paid_at', from: $start, to: $end)
 *     new Aggregation(AggregateFunction::Count, groupBy: 'level', limit: 20)
 *     new Aggregation(AggregateFunction::Count, dateColumn: 'created_at', bucket: TimeBucket::Month, from: $start, to: $end)
 *
 * Columns are plain fields of the source (FieldName rules), wrapped by the
 * query grammar; dates are bound parameters. Grouped results are capped
 * by $limit (at most 1000 groups).
 */
final readonly class Aggregation
{
    public const int MaxGroups = 1000;

    public function __construct(
        public AggregateFunction $function = AggregateFunction::Count,
        public ?string $column = null,
        public ?string $groupBy = null,
        public ?string $dateColumn = null,
        public ?TimeBucket $bucket = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
        public int $limit = 100,
    ) {
        foreach (['column' => $column, 'groupBy' => $groupBy, 'dateColumn' => $dateColumn] as $name => $field) {
            if ($field !== null && (! FieldName::isValid($field) || str_contains($field, '.'))) {
                throw InvalidQueryException::invalid($name, 'must be a column of the source itself.');
            }
        }

        if ($function !== AggregateFunction::Count && $column === null) {
            throw InvalidQueryException::invalid('column', sprintf('is required for %s.', $function->value));
        }

        if ($bucket !== null && $dateColumn === null) {
            throw InvalidQueryException::invalid('bucket', 'needs a dateColumn.');
        }

        if ($bucket !== null && $groupBy !== null) {
            throw InvalidQueryException::invalid('groupBy', 'cannot be combined with a time bucket.');
        }

        if (($from !== null || $to !== null) && $dateColumn === null) {
            throw InvalidQueryException::invalid('from', 'a date range needs a dateColumn.');
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw InvalidQueryException::invalid('from', 'must not be after to.');
        }

        if ($limit < 1 || $limit > self::MaxGroups) {
            throw InvalidQueryException::invalid('limit', sprintf('must be between 1 and %d.', self::MaxGroups));
        }
    }

    public function isGrouped(): bool
    {
        return $this->groupBy !== null || $this->bucket !== null;
    }

    /**
     * The same aggregate over another date range (comparison periods).
     */
    public function between(?DateTimeInterface $from, ?DateTimeInterface $to): self
    {
        return new self($this->function, $this->column, $this->groupBy, $this->dateColumn, $this->bucket, $from, $to, $this->limit);
    }
}
