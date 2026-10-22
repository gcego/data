<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

/**
 * ends_with: text ends with the value (case-insensitive). Text types only.
 */
final readonly class EndsWith extends TextOperator
{
    public function name(): string
    {
        return 'ends_with';
    }

    protected function pattern(string $escaped): string
    {
        return sprintf('%%%s', $escaped);
    }

    protected function position(): string
    {
        return 'end';
    }
}
