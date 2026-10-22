<?php

declare(strict_types=1);

namespace Gcego\Data\Sorting;

use Gcego\Data\Exceptions\InvalidSourceException;

enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    /**
     * @throws InvalidSourceException
     */
    public static function of(self|string $direction): self
    {
        if ($direction instanceof self) {
            return $direction;
        }

        return self::tryFrom(strtolower($direction)) ?? throw InvalidSourceException::invalidDefinition('Sort', sprintf('direction must be "asc" or "desc", "%s" given.', mb_substr($direction, 0, 20)));
    }
}
