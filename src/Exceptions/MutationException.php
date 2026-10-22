<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use LogicException;

/**
 * A write is not possible on a source: it is read-only, the operation is not
 * permitted by its Writable declaration, or its provider cannot write it.
 * This is a programming or configuration error (500), never caused by input;
 * invalid input raises Illuminate\Validation\ValidationException (422).
 */
final class MutationException extends LogicException implements DataException
{
    public static function readOnly(string $source): self
    {
        return new self(sprintf('Data source "%s" is read-only. Declare Writable::make([...]) to allow writes.', $source));
    }

    public static function operationNotAllowed(string $source, string $operation): self
    {
        return new self(sprintf('Data source "%s" does not allow %s. Add it to the Writable operations.', $source, $operation));
    }

    public static function unsupported(string $source, string $reason): self
    {
        return new self(sprintf('Data source "%s" cannot be written: %s', $source, $reason));
    }

    public static function outOfScope(string $source): self
    {
        return new self(sprintf('The write to data source "%s" would leave the record outside the source\'s permitted scope (tenant, scopes, fixed filters). It was rolled back.', $source));
    }
}
