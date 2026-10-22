<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * JSON array columns (tags, roles, permissions). The meaningful operator is
 * Contains (the array contains the value: whereJsonContains), plus null
 * checks; values are scalars. Collection sources match PHP arrays.
 */
final readonly class Json extends ValueType
{
    public function name(): string
    {
        return 'json';
    }

    public function describe(): string
    {
        return 'JSON array element (a string, number or boolean)';
    }

    protected function convert(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }
}
