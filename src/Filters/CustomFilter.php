<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use Closure;
use Gcego\Data\Contracts\FilterHandler;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A request filter with trusted, custom logic, for conditions a column
 * comparison cannot express:
 *
 *     new CustomFilter('overdue', fn (Builder $query, bool $overdue) => $overdue
 *         ? $query->where('due_at', '<', now())->whereNull('paid_at')
 *         : $query, ValueType::Boolean)
 *
 *     new CustomFilter('region', new RegionFilter, options: ['north', 'south'])
 *
 * The request sends filter[overdue]=1 (one value) or filter[region][]=north
 * (a list, when $multiple). Values are validated and cast with $type and
 * restricted to $options; the handler never sees raw input.
 */
final readonly class CustomFilter implements SourceConfiguration
{
    /**
     * @var list<string|int|float|bool>
     */
    public array $options;

    /**
     * @param  Closure(EloquentBuilder<Model>|QueryBuilder|Collection<array-key, mixed>, mixed, SourceDefinition): mixed|FilterHandler  $handler
     * @param  list<scalar|BackedEnum>  $options
     *
     * @throws InvalidSourceException
     */
    public function __construct(
        public string $key,
        public Closure|FilterHandler $handler,
        public ValueType $type = ValueType::String,
        public bool $multiple = false,
        array $options = [],
        public ?string $label = null,
    ) {
        if (preg_match(Filterable::KeyPattern, $key) !== 1) {
            throw InvalidSourceException::invalidDefinition('CustomFilter "'.mb_substr($key, 0, 64).'"', 'keys use lowercase letters, digits and "_", separated by ".".');
        }

        try {
            $this->options = array_map(static fn (mixed $option): string|int|float|bool => $type->cast($key, $option), array_values($options));
        } catch (InvalidFilterException) {
            throw InvalidSourceException::invalidDefinition('CustomFilter "'.$key.'"', sprintf('every option must be a valid %s.', $type->describe()));
        }
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

    /**
     * The request operator names this filter accepts.
     *
     * @return list<string>
     */
    public function operatorNames(): array
    {
        return $this->multiple ? ['eq', 'in'] : ['eq'];
    }

    /**
     * @return array{key: string, type: string, multiple: bool, options: list<string|int|float|bool>, label: string|null, handler: string}
     */
    public function normalize(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'multiple' => $this->multiple,
            'options' => $this->options,
            'label' => $this->label,
            'handler' => $this->handler instanceof Closure ? 'closure' : $this->handler::class,
        ];
    }
}
