<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

/**
 * not_contains: text does not contain the value (case-insensitive). Text types only.
 */
final readonly class NotContains extends TextOperator
{
    public function name(): string
    {
        return 'not_contains';
    }

    protected function pattern(string $escaped): string
    {
        return sprintf('%%%s%%', $escaped);
    }

    protected function position(): string
    {
        return 'any';
    }

    protected function negated(): bool
    {
        return true;
    }
}
