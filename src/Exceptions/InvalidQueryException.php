<?php

declare(strict_types=1);

namespace Gcego\Data\Exceptions;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Untrusted query input (filters, search, sort, pagination, includes) was rejected.
 *
 * Laravel renders it as 400 Bad Request. Messages name parameters and keys,
 * never the submitted values.
 */
class InvalidQueryException extends InvalidArgumentException implements DataException, HttpExceptionInterface
{
    public static function invalid(string $parameter, string $reason): static
    {
        return new static(sprintf('Query parameter "%s" %s', self::safe($parameter), $reason)); // @phpstan-ignore new.static
    }

    public static function tooLong(string $parameter, int $maximum): static
    {
        return static::invalid($parameter, sprintf('is longer than %d characters.', $maximum));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unknownInclude(string $include, array $allowed): static
    {
        return static::invalid('include', sprintf('requests "%s", which is not an allowed include%s', self::safe($include), self::allowed($allowed)));
    }

    public function getStatusCode(): int
    {
        return 400;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * A request-supplied key, shortened and stripped of control characters, for messages.
     */
    protected static function safe(string $value): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', mb_substr($value, 0, 64));
    }

    /**
     * @param  array<array-key, string>  $allowed
     */
    protected static function allowed(array $allowed): string
    {
        return $allowed === [] ? '; none are allowed.' : '. Allowed: '.implode(', ', $allowed).'.';
    }
}
