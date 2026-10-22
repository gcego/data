<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use DateTimeInterface;
use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Types\Boolean;
use Gcego\Data\Filters\Types\DateTime;
use Gcego\Data\Filters\Types\Decimal;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Support\FieldName;

/**
 * A trusted, mandatory condition, listed in Constraints::make([...]):
 *
 *     Filter::make('is_active', true)
 *     Filter::make('age', 18, GreaterThanOrEqual::make())
 *     Filter::make('status', ['draft', 'review'], In::make())
 *     Filter::make('archived_at', operator: IsNull::make())
 *     Filter::make('name', 'alg', StartsWith::make())
 *     Filter::make('reports', operator: DoesntHave::make())           // related records
 *     Filter::make('tags', 'urgent', Contains::make(), Json::make())  // explicit value type
 *
 * Equal with null means IS NULL. The value type is inferred from the value
 * (string, integer, decimal, boolean, date-time) unless given. Request input
 * can never remove a Filter; user filters are Filterables. Validated request
 * filters use the same class internally (QueryPlan::$filters).
 */
final readonly class Filter
{
    /**
     * @param  scalar|list<scalar>|null  $value
     */
    private function __construct(
        public string $field,
        public mixed $value,
        public Operator $operator,
        public FilterValueType $type,
    ) {}

    /**
     * @param  scalar|BackedEnum|DateTimeInterface|list<scalar|BackedEnum|DateTimeInterface>|null  $value
     *
     * @throws InvalidSourceException
     */
    public static function make(string $field, mixed $value = null, ?Operator $operator = null, ?FilterValueType $type = null): self
    {
        $node = 'Filter "'.mb_substr($field, 0, 64).'"';
        FieldName::assert('Filter', $field);
        $operator ??= Equal::make();
        $value = self::plain($node, $value);
        $type ??= self::infer($value);

        if (! $operator instanceof RelationOperator && ! $operator->supports($type)) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('the "%s" operator does not apply to %s values.', $operator->name(), $type->name()));
        }

        $valid = match ($operator->arity()) {
            0 => $value === null,
            1 => ! is_array($value) && ($value !== null || $operator instanceof Equal || $operator instanceof Operators\NotEqual),
            2 => is_array($value) && count($value) === 2,
            default => is_array($value) && $value !== [],
        };

        if (! $valid) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('"%s" takes %s.', $operator->name(), match ($operator->arity()) {
                0 => 'no value',
                1 => 'one value',
                2 => 'a list of exactly two values',
                default => 'a non-empty list of values',
            }));
        }

        try {
            $cast = $value === null || $operator->arity() === 0 ? null : (is_array($value) ? array_map(static fn (mixed $item): string|int|float|bool => $type->cast($field, $item), $value) : $type->cast($field, $value));
        } catch (InvalidFilterException) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('the value must be a valid %s.', $type->describe()));
        }

        return new self($field, $cast, $operator, $type);
    }

    /**
     * A filter built from validated request input (QueryResolver).
     *
     * @internal
     *
     * @param  scalar|list<scalar>|null  $value
     */
    public static function trusted(string $column, mixed $value, Operator $operator, FilterValueType $type): self
    {
        return new self($column, $value, $operator, $type);
    }

    public function operatorName(): string
    {
        return $this->operator->name();
    }

    /**
     * @return array{field: string, operator: string, value: scalar|list<scalar>|null}
     */
    public function normalize(): array
    {
        return ['field' => $this->field, 'operator' => $this->operator->name(), 'value' => $this->value];
    }

    /**
     * @return scalar|list<scalar>|null
     *
     * @throws InvalidSourceException
     */
    private static function plain(string $node, mixed $value): mixed
    {
        $scalar = static fn (mixed $item): string|int|float|bool|null => match (true) {
            $item === null, is_scalar($item) => $item,
            $item instanceof BackedEnum => $item->value,
            $item instanceof DateTimeInterface => $item->format('Y-m-d H:i:s'),
            default => throw InvalidSourceException::invalidDefinition($node, 'values must be scalars, backed enums or dates.'),
        };

        if (! is_array($value)) {
            return $scalar($value);
        }

        if (! array_is_list($value)) {
            throw InvalidSourceException::invalidDefinition($node, 'list values must be a list.');
        }

        return array_map(static fn (mixed $item): string|int|float|bool => $scalar($item) ?? throw InvalidSourceException::invalidDefinition($node, 'list values cannot be null.'), $value);
    }

    /**
     * @param  scalar|list<scalar>|null  $value
     */
    private static function infer(mixed $value): FilterValueType
    {
        $sample = is_array($value) ? ($value[0] ?? null) : $value;

        return match (true) {
            is_bool($sample) => Boolean::make(),
            is_int($sample) => Integer::make(),
            is_float($sample) => Decimal::make(),
            is_string($sample) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sample) === 1 => DateTime::make(),
            default => StringType::make(),
        };
    }
}
