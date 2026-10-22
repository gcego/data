<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use Gcego\Data\Contracts\CustomOperator;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * Allows request input to filter one field:
 *
 *     new Filterable('status', options: ['draft', 'published'])
 *     new Filterable('score', [FilterOperator::GreaterThanOrEqual, FilterOperator::LessThanOrEqual], ValueType::Integer)
 *     new Filterable('subject', column: 'subject_id', type: ValueType::Integer)
 *     new Filterable('topic', column: 'topics.name')            // Eloquent: whereHas('topics')
 *     Filterable::text('name')                                  // contains, starts_with, ends_with, ...
 *     Filterable::enum('status', SubjectStatus::class)          // only the enum's values
 *     Filterable::relation('topics')                            // filter[topics][has]=1, [doesnt_have]=1
 *     new Filterable('tags', new JsonContains)                  // a CustomOperator
 *
 * The request names the key ("status"); the column comes only from this
 * trusted definition. Values are validated and cast with $type, restricted to
 * $options when given, and always bound as parameters.
 */
final readonly class Filterable implements SourceConfiguration
{
    public const string KeyPattern = '/^[a-z][a-z0-9_]{0,63}(?:\.[a-z][a-z0-9_]{0,63}){0,3}$/';

    /**
     * @var list<FilterOperator|CustomOperator>
     */
    public array $operators;

    public string $column;

    /**
     * @var list<string|int|float|bool>
     */
    public array $options;

    /**
     * @param  FilterOperator|CustomOperator|list<FilterOperator|CustomOperator>  $operators
     * @param  list<scalar|BackedEnum>  $options  Allowed values (after casting). Empty allows any valid value.
     * @param  class-string<BackedEnum>|null  $enum  The enum the options come from (see enum()).
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public string $key,
        FilterOperator|CustomOperator|array $operators = [FilterOperator::Equals, FilterOperator::In],
        public ValueType $type = ValueType::String,
        ?string $column = null,
        array $options = [],
        public ?string $label = null,
        public ?string $enum = null,
    ) {
        if (preg_match(self::KeyPattern, $key) !== 1) {
            throw InvalidSourceException::invalidDefinition('Filterable "'.mb_substr($key, 0, 64).'"', 'keys use lowercase letters, digits and "_", separated by ".".');
        }

        $node = 'Filterable "'.$key.'"';
        $operators = is_array($operators) ? $operators : [$operators];

        if ($operators === [] || array_filter($operators, static fn (mixed $operator): bool => ! $operator instanceof FilterOperator && ! $operator instanceof CustomOperator) !== []) {
            throw InvalidSourceException::invalidDefinition($node, 'operators must be a non-empty list of FilterOperator cases or CustomOperator instances.');
        }

        $names = [];

        foreach ($operators as $operator) {
            if ($operator instanceof CustomOperator) {
                Operators::assertCustom($node, $operator);
            } elseif ($operator->isText() && $type !== ValueType::String) {
                throw InvalidSourceException::invalidDefinition($node, sprintf('%s needs ValueType::String.', $operator->value));
            }

            $name = Operators::name($operator);
            $names[$name] = isset($names[$name]) && $names[$name] !== $operator
                ? throw InvalidSourceException::invalidDefinition($node, sprintf('operator "%s" is declared twice.', $name))
                : $operator;
        }

        $relation = array_filter($names, static fn (FilterOperator|CustomOperator $operator): bool => $operator instanceof FilterOperator && $operator->isRelation());

        if ($relation !== [] && count($relation) !== count($names)) {
            throw InvalidSourceException::invalidDefinition($node, 'has and doesnt_have cannot be combined with value operators; declare a separate Filterable.');
        }

        $this->operators = array_values($names);
        $this->column = FieldName::assert('Filterable', $column ?? $key);

        try {
            $this->options = array_map(static fn (mixed $option): string|int|float|bool => $type->cast($key, $option), array_values($options));
        } catch (InvalidFilterException) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('every option must be a valid %s.', $type->describe()));
        }
    }

    /**
     * Text matching: contains, not_contains, starts_with, ends_with, eq and neq.
     *
     * @throws InvalidSourceException
     */
    public static function text(string $key, ?string $column = null, ?string $label = null): self
    {
        return new self($key, [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith, FilterOperator::Equals, FilterOperator::NotEquals], ValueType::String, $column, label: $label);
    }

    /**
     * A backed enum column: only the enum's values are accepted (eq, neq, in, not_in by default).
     *
     * @param  class-string<BackedEnum>  $enum
     * @param  FilterOperator|list<FilterOperator>  $operators
     *
     * @throws InvalidSourceException
     */
    public static function enum(string $key, string $enum, FilterOperator|array $operators = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn], ?string $column = null, ?string $label = null): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw InvalidSourceException::invalidDefinition('Filterable "'.$key.'"', sprintf('%s is not a backed enum.', $enum));
        }

        $cases = $enum::cases();
        $type = $cases !== [] && is_int($cases[0]->value) ? ValueType::Integer : ValueType::String;

        return new self($key, $operators, $type, $column, $cases, $label, $enum);
    }

    /**
     * The existence of related records (Eloquent): filter[topics][has]=1 or filter[topics][doesnt_have]=1.
     *
     * @throws InvalidSourceException
     */
    public static function relation(string $key, ?string $relation = null, ?string $label = null): self
    {
        return new self($key, [FilterOperator::Has, FilterOperator::DoesntHave], ValueType::Boolean, $relation ?? $key, label: $label);
    }

    public function allows(FilterOperator|CustomOperator $operator): bool
    {
        return in_array($operator, $this->operators, true);
    }

    /**
     * The allowed operator with this request name.
     */
    public function operator(string $name): FilterOperator|CustomOperator|null
    {
        foreach ($this->operators as $operator) {
            if (Operators::name($operator) === $name) {
                return $operator;
            }
        }

        return null;
    }

    public function isRelation(): bool
    {
        return $this->operators[0] instanceof FilterOperator && $this->operators[0]->isRelation();
    }

    /**
     * @return list<string>
     */
    public function operatorNames(): array
    {
        return array_map(Operators::name(...), $this->operators);
    }

    /**
     * @return array{key: string, column: string, type: string, operators: list<string>, options: list<string|int|float|bool>, label: string|null, enum: string|null}
     */
    public function normalize(): array
    {
        return [
            'key' => $this->key,
            'column' => $this->column,
            'type' => $this->type->value,
            'operators' => $this->operatorNames(),
            'options' => $this->options,
            'label' => $this->label,
            'enum' => $this->enum,
        ];
    }
}
