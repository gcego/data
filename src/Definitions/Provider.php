<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * A custom data provider registered with Data::provider('reports', ReportProvider::class).
 * Built-in providers are inferred: eloquent (Model), query (DatabaseTable,
 * BuilderFactory) and collection (Items).
 */
final readonly class Provider implements SourceConfiguration
{
    private function __construct(public string $name) {}

    /**
     * @throws InvalidSourceException
     */
    public static function make(string $name): self
    {
        return preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $name) === 1 ? new self($name) : throw InvalidSourceException::invalidDefinition('Provider', 'provider names are lowercase identifiers.');
    }

    /**
     * @return array{provider: string}
     */
    public function normalize(): array
    {
        return ['provider' => $this->name];
    }
}
