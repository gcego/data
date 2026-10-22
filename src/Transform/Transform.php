<?php

declare(strict_types=1);

namespace Gcego\Data\Transform;

use Closure;
use Gcego\Data\Contracts\RecordTransformer;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * A trusted server-side mapping applied to every returned record:
 *
 *     new Transform(fn (Subject $subject) => ['id' => $subject->id, 'name' => $subject->name])
 *     new Transform(new SubjectSummary)              // a RecordTransformer
 *     Transform::only('id', 'name', 'code')          // approved attributes
 *
 * only() reads models through toArray(), so attributes in a model's $hidden
 * stay hidden even when listed. Transforms never mutate the record.
 */
final readonly class Transform implements RecordTransformer, SourceConfiguration
{
    /**
     * @param  Closure(mixed): mixed|RecordTransformer  $transformer
     */
    public function __construct(public Closure|RecordTransformer $transformer) {}

    /**
     * @throws InvalidSourceException
     */
    public static function only(string ...$attributes): self
    {
        if ($attributes === []) {
            throw InvalidSourceException::invalidDefinition('Transform::only()', 'list at least one attribute.');
        }

        $keys = array_flip($attributes);

        return new self(static function (mixed $record) use ($keys): array {
            $data = match (true) {
                $record instanceof Model, $record instanceof Arrayable => $record->toArray(),
                is_array($record) => $record,
                is_object($record) => get_object_vars($record),
                default => [],
            };

            return array_intersect_key($data, $keys);
        });
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
}
