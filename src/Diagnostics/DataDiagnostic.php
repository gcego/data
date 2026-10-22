<?php

declare(strict_types=1);

namespace Gcego\Data\Diagnostics;

/**
 * One structured data-source finding.
 */
final readonly class DataDiagnostic
{
    public const string Ok = 'ok';

    public const string Warning = 'warning';

    public const string Error = 'error';

    /**
     * @param  'ok'|'warning'|'error'  $level
     * @param  array<string, mixed>  $details  Scalars and lists only; never record values or bindings.
     */
    public function __construct(
        public string $check,
        public string $level,
        public string $message,
        public array $details = [],
    ) {}

    /**
     * @return array{check: string, level: string, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['check' => $this->check, 'level' => $this->level, 'message' => $this->message, 'details' => $this->details];
    }
}
