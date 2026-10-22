<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use RuntimeException;

/**
 * An execution limit was exceeded by the data itself (not by request input),
 * such as a collection source returning more items than allowed.
 */
final class LimitExceededException extends RuntimeException implements DataException
{
    public static function collectionItems(string $source, int $maximum): self
    {
        return new self(sprintf('Collection source "%s" returned more than %d items. Use a database source, or raise Limits::make(maxItems: ...).', $source, $maximum));
    }
}
