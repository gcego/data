<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * Who may read and write one field of a source:
 *
 *     FieldAccess::hidden('password_hash')                       // never read or written through gcego/data
 *     FieldAccess::readOnly('code')                              // readable, never writable
 *     FieldAccess::ability('salary', read: 'viewSalary', write: 'updateSalary')
 *     new FieldAccess('diagnosis', read: fn (?User $user, mixed $record) => $user?->isClinician() ?? false)
 *
 * A rule is null (allowed), false (denied), a Gate ability (checked with the
 * record, or the model class when there is no record) or a trusted closure
 * receiving the user and the record (or null).
 *
 * A field the user may not read is removed from returned records and cannot
 * be filtered, sorted, searched or aggregated by that user (it would leak
 * through the results). A field the user may not write is rejected (403) by
 * create and update.
 */
final readonly class FieldAccess implements SourceConfiguration
{
    /**
     * @param  bool|string|Closure(mixed, mixed): bool|null  $read
     * @param  bool|string|Closure(mixed, mixed): bool|null  $write
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public string $field,
        public bool|string|Closure|null $read = null,
        public bool|string|Closure|null $write = null,
    ) {
        FieldName::assert('FieldAccess', $field);

        if (str_contains($field, '.')) {
            throw InvalidSourceException::invalidDefinition('FieldAccess "'.$field.'"', 'fields are attributes of the source\'s own records, not relation paths.');
        }

        foreach (['read' => $read, 'write' => $write] as $name => $rule) {
            if (is_string($rule) && trim($rule) === '') {
                throw InvalidSourceException::invalidDefinition('FieldAccess "'.$field.'"', sprintf('the %s ability must not be empty.', $name));
            }
        }
    }

    /**
     * @throws InvalidSourceException
     */
    public static function hidden(string $field): self
    {
        return new self($field, false, false);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function readOnly(string $field): self
    {
        return new self($field, null, false);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function ability(string $field, ?string $read = null, ?string $write = null): self
    {
        return new self($field, $read, $write);
    }

    /**
     * @return array{field: string, read: string, write: string}
     */
    public function normalize(): array
    {
        $describe = static fn (bool|string|Closure|null $rule): string => match (true) {
            $rule === null, $rule === true => 'allowed',
            $rule === false => 'denied',
            is_string($rule) => 'ability:'.$rule,
            default => 'closure',
        };

        return ['field' => $this->field, 'read' => $describe($this->read), 'write' => $describe($this->write)];
    }
}
