<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Support\Like;
use LogicException;

/**
 * Built-in filter operators. Values are the request names: filter[score][gte]=50.
 *
 * - Comparison: eq, neq, gt, gte, lt, lte, in, not_in, between, null, not_null.
 * - Text (string fields, case-insensitive, wildcards in values match literally):
 *   contains, not_contains, starts_with, ends_with.
 * - Relation (Eloquent sources; the field is a relation path):
 *   has, doesnt_have.
 *
 * Applications add their own operators with Contracts\CustomOperator.
 */
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case In = 'in';
    case NotIn = 'not_in';
    case Between = 'between';
    case Null = 'null';
    case NotNull = 'not_null';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Has = 'has';
    case DoesntHave = 'doesnt_have';

    /**
     * Number of values: 1 (scalar), -1 (non-empty list), 2 (pair) or 0 (none).
     */
    public function arity(): int
    {
        return match ($this) {
            self::In, self::NotIn => -1,
            self::Between => 2,
            self::Null, self::NotNull, self::Has, self::DoesntHave => 0,
            default => 1,
        };
    }

    /**
     * Whether the operator matches text (string fields only).
     */
    public function isText(): bool
    {
        return in_array($this, [self::Contains, self::NotContains, self::StartsWith, self::EndsWith], true);
    }

    /**
     * Whether the operator tests the existence of related records (Eloquent only).
     */
    public function isRelation(): bool
    {
        return $this === self::Has || $this === self::DoesntHave;
    }

    /**
     * The SQL comparison operator of single-value comparison operators.
     */
    public function comparison(): string
    {
        return match ($this) {
            self::Equals => '=',
            self::NotEquals => '!=',
            self::GreaterThan => '>',
            self::GreaterThanOrEqual => '>=',
            self::LessThan => '<',
            self::LessThanOrEqual => '<=',
            default => throw new LogicException(sprintf('Operator "%s" is not a comparison.', $this->value)),
        };
    }

    /**
     * The LIKE pattern of a text operator for an (unescaped) value.
     */
    public function pattern(string $value): string
    {
        $escaped = Like::escape($value);

        return match ($this) {
            self::Contains, self::NotContains => '%'.$escaped.'%',
            self::StartsWith => $escaped.'%',
            self::EndsWith => '%'.$escaped,
            default => throw new LogicException(sprintf('Operator "%s" is not a text operator.', $this->value)),
        };
    }

    /**
     * The value comparison operators (every operator except text and relation ones).
     *
     * @return list<self>
     */
    public static function comparisons(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $operator): bool => ! $operator->isText() && ! $operator->isRelation()));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $operator): string => $operator->value, self::cases());
    }
}
