<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * The source's logical name ("employees"): used in errors, diagnostics, the
 * query-builder ability and cache keys. Defaults to the snake-case plural of
 * the model, or the table name.
 */
final readonly class Name implements SourceConfiguration
{
    public const string Pattern = '/\A[a-z][a-z0-9_.\-]{0,63}\z/';

    private function __construct(public string $value) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $value): self
    {
        return preg_match(self::Pattern, $value) === 1
            ? new self($value)
            : throw InvalidSourceException::invalidDefinition('Name', 'names are 1 to 64 lowercase letters, digits, "_", "." or "-", starting with a letter.');
    }

    /**
     * @return array{name: string}
     */
    public function normalize(): array
    {
        return ['name' => $this->value];
    }
}
