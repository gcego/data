<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * A filter the request may apply, listed in Filterables::make([...]):
 *
 *     Filterable::make('age', [Integer::make(), GreaterThanOrEqual::make(), LessThan::make()])
 *     Filterable::make('status', [Enum::make(EmployeeStatus::class), Equal::make(), In::make()])
 *     Filterable::make('name', [StringType::make(), Contains::make(), StartsWith::make()])
 *     Filterable::make('department', [StringType::make(), Column::make('department.name')])
 *     Filterable::make('reports', [Has::make(), DoesntHave::make()])            // related records
 *     Filterable::make('age', [Integer::make()])                               // Equal only
 *
 * The configuration list holds exactly one value type (relation filters take
 * none), the permitted operators (Equal when none are listed; never every
 * operator), and optionally Options, Label and Column. Every operator must
 * apply to the value type. The request names the key; the column comes only
 * from this definition, and request values are validated and cast by the
 * type, restricted to the options, and always bound.
 */
final readonly class Filterable
{
    public const string KeyPattern = '/^[a-z][a-z0-9_]{0,63}(?:\.[a-z][a-z0-9_]{0,63}){0,3}$/';

    /**
     * @param  list<Operator>  $operators
     * @param  list<string|int|float|bool>  $options
     */
    private function __construct(
        public string $key,
        public ?FilterValueType $type,
        public array $operators,
        public string $column,
        public array $options,
        public ?string $label,
    ) {}

    /**
     * @param  list<FilterValueType|Operator|FilterOption>  $configuration
     *
     * @throws InvalidSourceException
     */
    public static function make(string $key, array $configuration): self
    {
        if (preg_match(self::KeyPattern, $key) !== 1) {
            throw InvalidSourceException::invalidDefinition('Filterable "'.mb_substr($key, 0, 64).'"', 'keys use lowercase letters, digits and "_", separated by ".".');
        }

        $parsed = FilterConfiguration::parse('Filterable "'.$key.'"', $key, $configuration);

        return new self($key, $parsed->type, $parsed->operators, FieldName::assert('Filterable', $parsed->column ?? $key), $parsed->options, $parsed->label);
    }

    /**
     * The permitted operator with this request name, or null.
     */
    public function operator(string $name): ?Operator
    {
        foreach ($this->operators as $operator) {
            if ($operator->name() === $name) {
                return $operator;
            }
        }

        return null;
    }

    public function isRelation(): bool
    {
        return $this->operators[0] instanceof RelationOperator;
    }

    /**
     * @return list<string>
     */
    public function operatorNames(): array
    {
        return array_map(static fn (Operator $operator): string => $operator->name(), $this->operators);
    }

    /**
     * @return array{key: string, column: string, type: string|null, operators: list<string>, options: list<string|int|float|bool>, label: string|null}
     */
    public function normalize(): array
    {
        return [
            'key' => $this->key,
            'column' => $this->column,
            'type' => $this->type?->name(),
            'operators' => $this->operatorNames(),
            'options' => $this->options,
            'label' => $this->label,
        ];
    }
}
