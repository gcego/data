<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use DateTimeInterface;
use Gcego\Data\Contracts\CustomOperator;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * A fixed, trusted condition that is always applied:
 *
 *     new Filter('is_published', true)
 *     new Filter('score', 50, FilterOperator::GreaterThanOrEqual)
 *     new Filter('status', ['draft', 'published'], FilterOperator::In)
 *     new Filter('archived_at', operator: FilterOperator::Null)
 *     new Filter('name', 'alg', FilterOperator::StartsWith)
 *     new Filter('topics', operator: FilterOperator::Has)      // Eloquent: has related topics
 *     new Filter('tags', 'php', new JsonContains)              // a CustomOperator
 *
 * Equals and NotEquals with null mean IS NULL and IS NOT NULL. A Filter is
 * mandatory: request input cannot remove it. To let users filter, declare a
 * Filterable instead.
 */
final readonly class Filter implements SourceConfiguration
{
    public FilterOperator|CustomOperator $operator;

    /**
     * @var scalar|list<scalar>|null
     */
    public mixed $value;

    /**
     * @param  scalar|BackedEnum|DateTimeInterface|list<scalar|BackedEnum|DateTimeInterface>|null  $value
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public string $field,
        mixed $value = null,
        FilterOperator|CustomOperator|string $operator = FilterOperator::Equals,
    ) {
        FieldName::assert('Filter', $field);
        $this->operator = match (true) {
            $operator instanceof FilterOperator => $operator,
            $operator instanceof CustomOperator => Operators::assertCustom('Filter "'.$field.'"', $operator),
            default => FilterOperator::tryFrom($operator) ?? throw InvalidSourceException::invalidDefinition('Filter "'.$field.'"', sprintf('unknown operator "%s". Use one of: %s.', mb_substr($operator, 0, 20), implode(', ', FilterOperator::names()))),
        };
        $this->value = self::value($field, $this->operator, $value);
    }

    /**
     * @return array{field: string, operator: string, value: scalar|list<scalar>|null}
     */
    public function normalize(): array
    {
        return ['field' => $this->field, 'operator' => $this->operatorName(), 'value' => $this->value];
    }

    public function operatorName(): string
    {
        return Operators::name($this->operator);
    }

    /**
     * @return scalar|list<scalar>|null
     */
    private static function value(string $field, FilterOperator|CustomOperator $operator, mixed $value): mixed
    {
        $scalar = static function (mixed $item) use ($field): string|int|float|bool|null {
            return match (true) {
                $item === null, is_scalar($item) => $item,
                $item instanceof BackedEnum => $item->value,
                $item instanceof DateTimeInterface => $item->format('Y-m-d H:i:s'),
                default => throw InvalidSourceException::invalidDefinition('Filter "'.$field.'"', 'values must be scalars, backed enums or dates.'),
            };
        };

        if ($operator instanceof FilterOperator && $operator->isText() && ! is_string($value)) {
            throw InvalidSourceException::invalidDefinition('Filter "'.$field.'"', sprintf('%s needs a string value.', $operator->value));
        }

        return match ($operator->arity()) {
            0 => null,
            1 => $scalar($value),
            default => is_array($value) && array_is_list($value) && $value !== [] && ($operator->arity() === -1 || count($value) === 2)
                ? array_map(static fn (mixed $item): string|int|float|bool => $scalar($item) ?? throw InvalidSourceException::invalidDefinition('Filter "'.$field.'"', 'list values cannot be null.'), $value)
                : throw InvalidSourceException::invalidDefinition('Filter "'.$field.'"', $operator === FilterOperator::Between ? 'between needs a list of exactly two values.' : 'in and not_in need a non-empty list.'),
        };
    }
}
