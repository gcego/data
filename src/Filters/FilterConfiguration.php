<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Operators\Equal;

/**
 * @internal Parses and validates the configuration list of Filterable and CustomFilter.
 */
final readonly class FilterConfiguration
{
    public const string OperatorNamePattern = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * @param  list<Operator>  $operators
     * @param  list<string|int|float|bool>  $options
     */
    private function __construct(
        public ?FilterValueType $type,
        public array $operators,
        public array $options,
        public ?string $label,
        public ?string $column,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items
     *
     * @throws InvalidSourceException
     */
    public static function parse(string $node, string $key, array $items): self
    {
        if ($items === [] || ! array_is_list($items)) {
            throw InvalidSourceException::invalidDefinition($node, 'declare a list with a value type (Integer::make(), StringType::make(), ...) and optionally operators, Options, Label and Column.');
        }

        $type = null;
        $operators = [];
        $options = null;
        $label = null;
        $column = null;
        $twice = static fn (string $what): never => throw InvalidSourceException::invalidDefinition($node, sprintf('declare %s once.', $what));

        foreach ($items as $item) {
            match (true) {
                $item instanceof FilterValueType => $type === null ? $type = $item : $twice('one value type'),
                $item instanceof Operator => isset($operators[$item->name()]) ? $twice(sprintf('the "%s" operator', $item->name())) : $operators[$item->name()] = self::operator($node, $item),
                $item instanceof Options => $options === null ? $options = $item : $twice('Options'),
                $item instanceof Label => $label === null ? $label = $item->text : $twice('Label'),
                $item instanceof Column => $column === null ? $column = $item->name : $twice('Column'),
                default => throw InvalidSourceException::invalidDefinition($node, sprintf('%s is not a value type, operator, Options, Label or Column.', get_debug_type($item))),
            };
        }

        $relation = array_filter($operators, static fn (Operator $operator): bool => $operator instanceof RelationOperator);

        if ($relation !== []) {
            if (count($relation) !== count($operators) || $type !== null || $options !== null) {
                throw InvalidSourceException::invalidDefinition($node, 'has and doesnt_have take no value type, options or other operators; declare a separate filter.');
            }

            return new self(null, array_values($operators), [], $label, $column);
        }

        if ($type === null) {
            throw InvalidSourceException::invalidDefinition($node, 'declare exactly one value type, such as Integer::make() or StringType::make().');
        }

        $operators = $operators === [] ? ['eq' => Equal::make()] : $operators;

        foreach ($operators as $name => $operator) {
            if (! $operator->supports($type)) {
                throw InvalidSourceException::invalidDefinition($node, sprintf('the "%s" operator does not apply to %s values.', $name, $type->name()));
            }
        }

        return new self($type, array_values($operators), self::options($node, $key, $type, $options), $label, $column);
    }

    /**
     * @throws InvalidSourceException
     */
    private static function operator(string $node, Operator $operator): Operator
    {
        if (preg_match(self::OperatorNamePattern, $operator->name()) !== 1 || ! in_array($operator->arity(), [-1, 0, 1, 2], true)) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('operator %s needs a lowercase request name and an arity of -1, 0, 1 or 2.', $operator::class));
        }

        return $operator;
    }

    /**
     * @return list<string|int|float|bool>
     *
     * @throws InvalidSourceException
     */
    private static function options(string $node, string $key, FilterValueType $type, ?Options $options): array
    {
        if ($options === null) {
            return $type->options();
        }

        if ($type->options() !== []) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('%s values already restrict the options; remove Options.', $type->name()));
        }

        try {
            return array_values(array_unique(array_map(static fn (mixed $option): string|int|float|bool => $type->cast($key, $option), $options->values), SORT_REGULAR));
        } catch (InvalidFilterException) {
            throw InvalidSourceException::invalidDefinition($node, sprintf('every option must be a valid %s.', $type->describe()));
        }
    }
}
