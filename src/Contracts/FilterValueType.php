<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Exceptions\InvalidFilterException;

/**
 * The value type of a filter: how request values are validated and cast, and
 * which kinds of operator make sense for it.
 *
 * Built in: StringType, Integer, Decimal, Boolean, Date, DateTime, Time,
 * Enum, Uuid and Json (Gcego\Data\Filters\Types). Applications implement this
 * contract for their own types. Implementations must be immutable.
 */
interface FilterValueType
{
    /**
     * A short identifier for diagnostics and tooling ("integer").
     */
    public function name(): string;

    /**
     * Validate and cast one untrusted value. The result is always bound as a parameter.
     *
     * @throws InvalidFilterException with a message that names the field, never the value.
     */
    public function cast(string $field, mixed $value): string|int|float|bool;

    /**
     * What a valid value looks like, for error messages ("integer", "date (YYYY-MM-DD)").
     */
    public function describe(): string;

    /**
     * Whether values have an order (gt, gte, lt, lte, between apply).
     */
    public function ordered(): bool;

    /**
     * Whether values are text (contains, starts_with, ends_with apply).
     */
    public function textual(): bool;

    /**
     * The only accepted values (after casting), or [] for any valid value.
     *
     * @return list<string|int|float|bool>
     */
    public function options(): array;
}
