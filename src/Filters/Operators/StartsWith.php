<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Operators;

/**
 * starts_with: text starts with the value (case-insensitive). Text types only.
 */
final readonly class StartsWith extends TextOperator
{
    public function name(): string
    {
        return 'starts_with';
    }

    protected function pattern(string $escaped): string
    {
        return sprintf('%s%%', $escaped);
    }

    protected function position(): string
    {
        return 'start';
    }
}
