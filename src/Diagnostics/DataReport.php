<?php

declare(strict_types=1);

namespace Gcego\Data\Diagnostics;

/**
 * Machine-readable output of QueryInspector::inspect().
 */
final readonly class DataReport
{
    /**
     * @param  array<string, mixed>  $source  SourceDefinition::toArray() plus capabilities, or [] when it does not compile.
     * @param  list<DataDiagnostic>  $diagnostics
     */
    public function __construct(
        public array $source,
        public array $diagnostics,
    ) {}

    /**
     * @return list<DataDiagnostic>
     */
    public function check(string $check): array
    {
        return array_values(array_filter($this->diagnostics, static fn (DataDiagnostic $diagnostic): bool => $diagnostic->check === $check));
    }

    public function isHealthy(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->level === DataDiagnostic::Error) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{healthy: bool, source: array<string, mixed>, diagnostics: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'source' => $this->source,
            'diagnostics' => array_map(static fn (DataDiagnostic $diagnostic): array => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
