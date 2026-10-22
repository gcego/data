<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * UUIDs (any version), normalized to lowercase.
 */
final readonly class Uuid extends ValueType
{
    public function name(): string
    {
        return 'uuid';
    }

    public function describe(): string
    {
        return 'UUID';
    }

    protected function convert(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? strtolower($value) : null;
    }
}
