<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

/**
 * true, false, 1 or 0.
 */
final readonly class Boolean extends ValueType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function describe(): string
    {
        return 'boolean (true, false, 1 or 0)';
    }

    protected function convert(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }
}
