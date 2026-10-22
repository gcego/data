<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\FilterHandler;
use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Contracts\Operator;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A request filter with trusted custom logic, listed in Filterables::make([...]):
 *
 *     CustomFilter::make('overdue', fn (Builder $query, bool $overdue) => $overdue
 *         ? $query->where('due_at', '<', now())->whereNull('paid_at')
 *         : $query, [Boolean::make()])
 *
 *     CustomFilter::make('region', new RegionFilter, [StringType::make(), In::make(), Options::make(['north', 'south'])])
 *
 * The configuration holds exactly one value type, optionally Equal and/or In
 * (Equal when none), Options and Label. The handler receives only the
 * validated value, inside the request filters' own AND group.
 */
final readonly class CustomFilter
{
    /**
     * @param  list<Operator>  $operators
     * @param  list<string|int|float|bool>  $options
     */
    private function __construct(
        public string $key,
        public Closure|FilterHandler $handler,
        public FilterValueType $type,
        public array $operators,
        public array $options,
        public ?string $label,
    ) {}

    /**
     * @param  Closure(EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>, mixed, SourceDefinition): mixed|FilterHandler  $handler
     * @param  list<FilterValueType|Operator|FilterOption>  $configuration
     *
     * @throws InvalidSourceException
     */
    public static function make(string $key, Closure|FilterHandler $handler, array $configuration): self
    {
        $node = 'CustomFilter "'.mb_substr($key, 0, 64).'"';

        if (preg_match(Filterable::KeyPattern, $key) !== 1) {
            throw InvalidSourceException::invalidDefinition($node, 'keys use lowercase letters, digits and "_", separated by ".".');
        }

        $parsed = FilterConfiguration::parse($node, $key, $configuration);

        foreach ($parsed->operators as $operator) {
            if (! $operator instanceof Equal && ! $operator instanceof In) {
                throw InvalidSourceException::invalidDefinition($node, 'custom filters accept Equal and In only.');
            }
        }

        if ($parsed->type === null || $parsed->column !== null) {
            throw InvalidSourceException::invalidDefinition($node, 'custom filters take a value type and no Column.');
        }

        return new self($key, $handler, $parsed->type, $parsed->operators, $parsed->options, $parsed->label);
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>  $query
     * @param  scalar|list<scalar>  $value
     * @return EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>|null
     */
    public function apply(EloquentBuilder|QueryBuilder|Collection $query, mixed $value, SourceDefinition $source): EloquentBuilder|QueryBuilder|Collection|null
    {
        $result = $this->handler instanceof FilterHandler
            ? $this->handler->apply($query, $value, $source)
            : ($this->handler)($query, $value, $source);

        return $result instanceof EloquentBuilder || $result instanceof QueryBuilder || $result instanceof Collection ? $result : null;
    }

    public function operator(string $name): ?Operator
    {
        foreach ($this->operators as $operator) {
            if ($operator->name() === $name) {
                return $operator;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function operatorNames(): array
    {
        return array_map(static fn (Operator $operator): string => $operator->name(), $this->operators);
    }

    /**
     * @return array{key: string, type: string, operators: list<string>, options: list<string|int|float|bool>, label: string|null, handler: string}
     */
    public function normalize(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->name(),
            'operators' => $this->operatorNames(),
            'options' => $this->options,
            'label' => $this->label,
            'handler' => $this->handler instanceof Closure ? 'closure' : $this->handler::class,
        ];
    }
}
