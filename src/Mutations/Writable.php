<?php

declare(strict_types=1);

namespace Gcego\Data\Mutations;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Support\FieldName;

/**
 * Makes a source writable: the allowlist of fields input may set, their
 * Laravel validation rules, and the permitted operations.
 *
 *     new Writable([
 *         'name' => ['required', 'string', 'max:120'],
 *         'code' => fn (?Subject $subject) => ['nullable', Rule::unique('subjects')->ignore($subject)],
 *         'level' => 'integer|between:1,6',
 *         'notes',                                        // writable, no rules
 *     ])
 *
 *     new Writable(['name' => 'required|string'], operations: [DataOperation::Create, DataOperation::Update])
 *
 * Input keys outside the allowlist are rejected (422), never silently
 * ignored. On update, rules apply only to the fields present (a "sometimes"
 * rule is prepended), so updates may be partial. Rules given as closures
 * receive the record being updated (null on create). Sources without Writable
 * are read-only.
 */
final readonly class Writable implements SourceConfiguration
{
    public const array DefaultOperations = [DataOperation::Create, DataOperation::Update, DataOperation::Delete];

    /**
     * @var array<string, string|array<mixed>|Closure|null> field => rules
     */
    public array $fields;

    /**
     * @var list<DataOperation>
     */
    public array $operations;

    /**
     * @param  array<array-key, mixed>  $fields  field => rules, or a field name without rules.
     * @param  list<DataOperation>  $operations
     *
     * @throws InvalidSourceException
     */
    public function __construct(array $fields, array $operations = self::DefaultOperations)
    {
        $normalized = [];

        foreach ($fields as $key => $value) {
            [$field, $rules] = is_int($key) ? [$value, null] : [$key, $value];

            if (! is_string($field) || str_contains(FieldName::assert('Writable', $field), '.')) {
                throw InvalidSourceException::invalidDefinition('Writable', 'fields are attribute names of the source\'s own records.');
            }

            if (! ($rules === null || is_string($rules) || is_array($rules) || $rules instanceof Closure)) {
                throw InvalidSourceException::invalidDefinition('Writable', sprintf('the rules of "%s" must be a string, an array or a closure.', $field));
            }

            $normalized[$field] = $rules;
        }

        if ($normalized === []) {
            throw InvalidSourceException::invalidDefinition('Writable', 'declare at least one writable field.');
        }

        foreach ($operations as $operation) {
            if (! $operation instanceof DataOperation || ! $operation->isWrite()) {
                throw InvalidSourceException::invalidDefinition('Writable', 'operations must be write operations: Create, Update, Delete, Restore or ForceDelete.');
            }
        }

        $this->fields = $normalized;
        $this->operations = array_values(array_unique($operations, SORT_REGULAR));
    }

    public function allows(DataOperation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Laravel validation rules for an operation.
     *
     * @return array<string, mixed>
     */
    public function rules(DataOperation $operation, mixed $record = null): array
    {
        $rules = [];

        foreach ($this->fields as $field => $definition) {
            $definition = $definition instanceof Closure ? $definition($record) : $definition;

            if ($definition === null || $definition === [] || $definition === '') {
                continue;
            }

            $list = is_string($definition) ? explode('|', $definition) : (is_array($definition) ? array_values($definition) : [$definition]);
            $rules[$field] = $operation === DataOperation::Update ? ['sometimes', ...$list] : $list;
        }

        return $rules;
    }

    /**
     * @return array{fields: list<string>, operations: list<string>}
     */
    public function normalize(): array
    {
        return [
            'fields' => array_keys($this->fields),
            'operations' => array_map(static fn (DataOperation $operation): string => $operation->value, $this->operations),
        ];
    }
}
