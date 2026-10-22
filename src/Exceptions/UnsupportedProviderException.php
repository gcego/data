<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use LogicException;

/**
 * A provider is unknown, or cannot perform an operation the source asks for.
 */
final class UnsupportedProviderException extends LogicException implements DataException
{
    /**
     * @param  list<string>  $registered
     */
    public static function unknown(string $provider, array $registered): self
    {
        return new self(sprintf('Data provider "%s" is not registered. Registered providers: %s.', $provider, implode(', ', $registered)));
    }

    public static function unsupported(string $provider, string $operation): self
    {
        return new self(sprintf('Data provider "%s" does not support %s.', $provider, $operation));
    }

    public static function conflict(string $provider): self
    {
        return new self(sprintf('Data provider "%s" is already registered. Pass override: true to replace it.', $provider));
    }
}
