<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * A human-readable name for filter UIs: Label::make('Date of birth').
 */
final readonly class Label implements FilterOption
{
    private function __construct(public string $text) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $text): self
    {
        return trim($text) === '' || mb_strlen($text) > 120
            ? throw InvalidSourceException::invalidDefinition('Label', 'labels are 1 to 120 characters.')
            : new self($text);
    }
}
