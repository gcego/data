<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Sources;

use LogicException;

/**
 * A class that is not a SourceProvider; it must never be instantiated.
 */
final class NotASource
{
    public function __construct()
    {
        throw new LogicException('NotASource was instantiated.');
    }
}
