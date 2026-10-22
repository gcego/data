<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use InvalidArgumentException;

/**
 * A developer-authored source definition is invalid. This is a programming error, never caused by request input.
 */
final class InvalidSourceException extends InvalidArgumentException implements DataException
{
    public static function invalid(string $source, string $reason): self
    {
        return new self(sprintf('Data source "%s" is invalid: %s', $source, $reason));
    }

    public static function invalidModel(string $model): self
    {
        return new self(sprintf('"%s" is not an Eloquent model class. Pass a class that extends Illuminate\Database\Eloquent\Model.', $model));
    }

    public static function invalidField(string $kind, string $field): self
    {
        return new self(sprintf('%s field "%s" is not a valid column or relation path. Use letters, digits and "_", separated by ".".', $kind, mb_substr($field, 0, 80)));
    }

    public static function invalidDefinition(string $node, string $reason): self
    {
        return new self(sprintf('%s is invalid: %s', $node, $reason));
    }
}
