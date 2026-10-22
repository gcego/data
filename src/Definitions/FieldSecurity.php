<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Security\FieldAccess;

/**
 * Who may read and write individual fields:
 *
 *     FieldSecurity::make([
 *         FieldAccess::hidden('password_hash'),
 *         FieldAccess::readOnly('employee_number'),
 *         FieldAccess::ability('salary', read: 'viewSalary', write: 'updateSalary'),
 *         FieldAccess::hidden('manager.salary'),                 // a field of an included relation
 *     ])
 */
final readonly class FieldSecurity implements SourceConfiguration
{
    /**
     * @param  array<string, FieldAccess>  $fields
     */
    private function __construct(public array $fields) {}

    /**
     * @param  list<FieldAccess>  $rules
     *
     * @throws InvalidSourceException
     */
    public static function make(array $rules): self
    {
        if ($rules === [] || ! array_is_list($rules)) {
            throw InvalidSourceException::invalidDefinition('FieldSecurity', 'declare a non-empty list of FieldAccess rules.');
        }

        $fields = [];

        foreach ($rules as $rule) {
            if (! $rule instanceof FieldAccess) {
                throw InvalidSourceException::invalidDefinition('FieldSecurity', sprintf('%s is not a FieldAccess rule.', get_debug_type($rule)));
            }

            $fields[$rule->field] = isset($fields[$rule->field])
                ? throw InvalidSourceException::invalidDefinition('FieldSecurity', sprintf('the field "%s" is declared twice.', $rule->field))
                : $rule;
        }

        return new self($fields);
    }

    /**
     * @return array{fields: list<array<string, string>>}
     */
    public function normalize(): array
    {
        return ['fields' => array_values(array_map(static fn (FieldAccess $access): array => $access->normalize(), $this->fields))];
    }
}
