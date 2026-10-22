<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * Trusted server-side mapping of one record (a model, a row object or a collection item).
 */
interface RecordTransformer
{
    public function transform(mixed $record): mixed;
}
