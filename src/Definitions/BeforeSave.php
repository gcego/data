<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Security\DataOperation;

/**
 * Trusted server-side preparation of validated attributes before they are saved:
 *
 *     BeforeSave::make(fn (array $attributes, DataOperation $operation, ?Subject $record) => [
 *         ...$attributes,
 *         'slug' => Str::slug($attributes['name'] ?? $record?->name ?? ''),
 *     ])
 *
 * It runs inside the transaction, after validation and field checks, and
 * before the tenant column is stamped (which it cannot override). It must
 * return the attributes to save. It may throw to abort the operation.
 */
final readonly class BeforeSave implements SourceConfiguration
{
    /**
     * @param  Closure(array<string, mixed>, DataOperation, mixed): array<string, mixed>  $callback
     * @param  list<DataOperation>  $operations  Create and/or Update.
     *
     * @throws InvalidSourceException
     */
    private function __construct(
        public Closure $callback,
        public array $operations,
    ) {
        foreach ($operations as $operation) {
            if ($operation !== DataOperation::Create && $operation !== DataOperation::Update) {
                throw InvalidSourceException::invalidDefinition('BeforeSave', 'operations must be Create and/or Update.');
            }
        }
    }

    /**
     * @param  Closure(array<string, mixed>, DataOperation, mixed): array<string, mixed>  $callback
     * @param  list<DataOperation>  $operations  Create and/or Update.
     *
     * @throws InvalidSourceException
     */
    public static function make(Closure $callback, array $operations = [DataOperation::Create, DataOperation::Update]): self
    {
        return new self($callback, $operations);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws InvalidSourceException
     */
    public function prepare(array $attributes, DataOperation $operation, mixed $record): array
    {
        if (! in_array($operation, $this->operations, true)) {
            return $attributes;
        }

        $result = ($this->callback)($attributes, $operation, $record);

        if (! is_array($result) || ($result !== [] && array_is_list($result))) {
            throw InvalidSourceException::invalidDefinition('BeforeSave', 'the callback must return the attributes to save (field => value).');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @return array{operations: list<string>}
     */
    public function normalize(): array
    {
        return ['operations' => array_map(static fn (DataOperation $operation): string => $operation->value, $this->operations)];
    }
}
