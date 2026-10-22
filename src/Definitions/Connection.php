<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * The database connection of a model or table source (a model's own connection by default).
 */
final readonly class Connection implements SourceConfiguration
{
    private function __construct(public string $name) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $name): self
    {
        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) === 1 ? new self($name) : throw InvalidSourceException::invalidDefinition('Connection', 'connection names are 1 to 64 letters, digits, "_", "." or "-".');
    }

    /**
     * @return array{connection: string}
     */
    public function normalize(): array
    {
        return ['connection' => $this->name];
    }
}
