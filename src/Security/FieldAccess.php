<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

use Closure;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Relationships\RelationPaths;
use Gcego\Data\Support\FieldName;

/**
 * Who may read and write one field, listed in FieldSecurity::make([...]):
 *
 *     FieldAccess::hidden('password_hash')                       // never read or written through gcego/data
 *     FieldAccess::readOnly('employee_number')                   // readable, never writable
 *     FieldAccess::ability('salary', read: 'viewSalary', write: 'updateSalary')
 *     FieldAccess::make('diagnosis', read: fn (?User $user, mixed $record) => $user?->isClinician() ?? false)
 *     FieldAccess::hidden('manager.salary')                      // a field of an eager-loaded relation
 *
 * A rule is null (allowed), false (denied), a Gate ability (checked with the
 * record, or the model class when there is no record) or a trusted closure
 * receiving the user and the record (or null).
 *
 * A field the user may not read is removed from returned records (relation
 * fields from the loaded related records) and cannot be filtered, sorted,
 * searched or aggregated by that user. A field the user may not write is
 * rejected (403) by create and update. Relation fields take read rules only.
 */
final readonly class FieldAccess
{
    /**
     * @param  bool|string|Closure(mixed, mixed): bool|null  $read
     * @param  bool|string|Closure(mixed, mixed): bool|null  $write
     */
    private function __construct(
        public string $field,
        public bool|string|Closure|null $read,
        public bool|string|Closure|null $write,
    ) {}

    /**
     * @param  bool|string|Closure(mixed, mixed): bool|null  $read
     * @param  bool|string|Closure(mixed, mixed): bool|null  $write
     *
     * @throws InvalidSourceException
     */
    public static function make(string $field, bool|string|Closure|null $read = null, bool|string|Closure|null $write = null): self
    {
        RelationPaths::assertDepth('FieldAccess', FieldName::assert('FieldAccess', $field), column: true);

        foreach (['read' => $read, 'write' => $write] as $name => $rule) {
            if (is_string($rule) && trim($rule) === '') {
                throw InvalidSourceException::invalidDefinition('FieldAccess "'.$field.'"', sprintf('the %s ability must not be empty.', $name));
            }
        }

        if (str_contains($field, '.') && $write !== null && $write !== false) {
            throw InvalidSourceException::invalidDefinition('FieldAccess "'.$field.'"', 'relation fields take read rules only; they are never written through this source.');
        }

        return new self($field, $read, $write);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function hidden(string $field): self
    {
        return self::make($field, false, false);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function readOnly(string $field): self
    {
        return self::make($field, null, false);
    }

    /**
     * @throws InvalidSourceException
     */
    public static function ability(string $field, ?string $read = null, ?string $write = null): self
    {
        return self::make($field, $read, $write);
    }

    public function isRelationField(): bool
    {
        return str_contains($this->field, '.');
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
