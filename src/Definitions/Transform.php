<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\RecordTransformer;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The output shape of every returned record (after field security, and for
 * every caller, cached or not):
 *
 *     Transform::only(['id', 'name', 'email'])          // approved attributes
 *     Transform::except(['internal_notes'])             // everything else
 *     Transform::map(['id', 'title' => 'name', 'reports' => fn ($e) => $e->reports_count])
 *     Transform::resource(EmployeeResource::class)      // a Laravel API resource
 *     Transform::make(fn (Employee $e) => [...])        // trusted closure or RecordTransformer
 *
 * only(), except() and map() read models through toArray(), so attributes in
 * a model's $hidden (and fields removed by FieldAccess) stay hidden even when
 * listed. map() always returns every listed key, in order (null when absent),
 * so the output shape is predictable. Transforms never mutate the record.
 */
final readonly class Transform implements RecordTransformer, SourceConfiguration
{
    /**
     * @param  Closure(mixed): mixed|RecordTransformer  $transformer
     */
    private function __construct(public Closure|RecordTransformer $transformer) {}

    /**
     * @param  Closure(mixed): mixed|RecordTransformer  $transformer
     */
    public static function make(Closure|RecordTransformer $transformer): self
    {
        return new self($transformer);
    }

    /**
     * @param  list<string>  $attributes
     *
     * @throws InvalidSourceException
     */
    public static function only(array $attributes): self
    {
        if ($attributes === []) {
            throw InvalidSourceException::invalidDefinition('Transform::only()', 'list at least one attribute.');
        }

        $keys = array_flip(self::names($attributes));

        return new self(static fn (mixed $record): array => array_intersect_key(self::data($record), $keys));
    }

    /**
     * @param  list<string>  $attributes
     *
     * @throws InvalidSourceException
     */
    public static function except(array $attributes): self
    {
        if ($attributes === []) {
            throw InvalidSourceException::invalidDefinition('Transform::except()', 'list at least one attribute.');
        }

        $keys = array_flip(self::names($attributes));

        return new self(static fn (mixed $record): array => array_diff_key(self::data($record), $keys));
    }

    /**
     * A fixed output shape: output key => attribute (dot notation for relations) or a closure of the record.
     *
     * @param  array<array-key, string|Closure(mixed): mixed>  $fields  A plain string maps an attribute to itself.
     *
     * @throws InvalidSourceException
     */
    public static function map(array $fields): self
    {
        $shape = [];

        foreach ($fields as $output => $field) {
            $output = is_int($output) ? $field : $output;

            if (! is_string($output) || ! (is_string($field) || $field instanceof Closure)) {
                throw InvalidSourceException::invalidDefinition('Transform::map()', 'entries are attribute names, output => attribute, or output => closure.');
            }

            $shape[$output] = $field;
        }

        if ($shape === []) {
            throw InvalidSourceException::invalidDefinition('Transform::map()', 'list at least one field.');
        }

        return new self(static function (mixed $record) use ($shape): array {
            $data = self::data($record);
            $output = [];

            foreach ($shape as $key => $field) {
                $output[$key] = $field instanceof Closure ? $field($record) : data_get($data, $field);
            }

            return $output;
        });
    }

    /**
     * Shape every record with a Laravel API resource (its toArray() for the current request).
     *
     * @param  class-string<JsonResource>  $resource
     *
     * @throws InvalidSourceException
     */
    public static function resource(string $resource): self
    {
        if (! is_subclass_of($resource, JsonResource::class)) {
            throw InvalidSourceException::invalidDefinition('Transform::resource()', sprintf('%s is not a %s.', $resource, JsonResource::class));
        }

        return new self(static fn (mixed $record): mixed => (new $resource($record))->resolve());
    }

    public function transform(mixed $record): mixed
    {
        return $this->transformer instanceof RecordTransformer
            ? $this->transformer->transform($record)
            : ($this->transformer)($record);
    }

    /**
     * @return array{transformer: string}
     */
    public function normalize(): array
    {
        return ['transformer' => $this->transformer instanceof Closure ? 'closure' : $this->transformer::class];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function data(mixed $record): array
    {
        return match (true) {
            $record instanceof Model, $record instanceof Arrayable => $record->toArray(),
            is_array($record) => $record,
            is_object($record) => get_object_vars($record),
            default => [],
        };
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return list<string>
     *
     * @throws InvalidSourceException
     */
    private static function names(array $attributes): array
    {
        return array_values(array_map(static fn (mixed $attribute): string => is_string($attribute) && $attribute !== '' ? $attribute : throw InvalidSourceException::invalidDefinition('Transform', 'attributes are non-empty strings.'), $attributes));
    }
}
