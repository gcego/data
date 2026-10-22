<?php

declare(strict_types=1);

namespace Gcego\Data\Mutations;

use Closure;
use Gcego\Data\Caching\QueryCache;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Contracts\SupportsMutations;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Events\RecordCreated;
use Gcego\Data\Events\RecordDeleted;
use Gcego\Data\Events\RecordRestored;
use Gcego\Data\Events\RecordUpdated;
use Gcego\Data\Events\RecordWritten;
use Gcego\Data\Exceptions\MissingTenantException;
use Gcego\Data\Exceptions\MutationException;
use Gcego\Data\Exceptions\RecordNotFoundException;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Results\MutationResult;
use Gcego\Data\Security\DataOperation;
use Gcego\Data\Security\FieldGuard;
use Gcego\Data\Tenancy\TenantGuard;
use Gcego\Data\Transactions\TransactionManager;
use Gcego\Data\Transactions\TransactionOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Controlled writes. Every operation, in order:
 *
 *  1. the source must declare Writable with the operation, and its provider
 *     must support writing it (MutationException otherwise)
 *  2. a transaction starts (TransactionManager: rollback, deadlock retries)
 *  3. update / delete / restore lock the record by key inside the source's
 *     scope (tenant, scopes, fixed filters); a record outside it is 404
 *  4. authorization: create against the source, the rest against the record
 *  5. input: only Writable fields (422 for others, and for the tenant
 *     column), field write rules (403), Laravel validation (422)
 *  6. BeforeSave callbacks, then the tenant column is stamped on create
 *  7. the provider writes; the record must still be inside the source's
 *     scope afterwards, or everything rolls back (MutationException)
 *  8. after commit: cache invalidation and RecordCreated / RecordUpdated /
 *     RecordDeleted / RecordRestored events
 *
 * The returned record is presented like a read: unreadable fields removed,
 * Transform nodes applied.
 */
final readonly class MutationPipeline
{
    public function __construct(
        private ProviderRegistry $providers,
        private QueryPipeline $queries,
        private DataAuthorizer $authorizer,
        private TenantGuard $tenants,
        private FieldGuard $fields,
        private ValidationFactory $validation,
        private TransactionManager $transactions,
        private QueryCache $cache,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException|AuthorizationException|MutationException|MissingTenantException
     */
    public function create(SourceDefinition $source, array $input, QueryContext $context): MutationResult
    {
        $provider = $this->writer($source, DataOperation::Create);
        $this->authorize($source, DataOperation::Create, $context);
        $column = $this->tenants->column($source);
        $tenant = $column === null ? null : ($this->tenants->tenant($context) ?? throw MissingTenantException::for($source->name));

        return $this->transaction($source, $provider, function () use ($source, $provider, $input, $context, $column, $tenant): MutationResult {
            $attributes = $this->prepare($source, DataOperation::Create, $this->validated($source, DataOperation::Create, $input, $context, null), null);

            if ($column !== null) {
                $attributes[$column] = $tenant;
            }

            $record = $provider->create($source, $attributes);
            $key = $provider->keyOf($source, $record);
            $visible = $this->visible($source, $provider, $key, $context);

            return $this->written($source, DataOperation::Create, $key, $visible, $attributes, [], $context, RecordCreated::class);
        });
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException|AuthorizationException|MutationException|RecordNotFoundException
     */
    public function update(SourceDefinition $source, string|int $key, array $input, QueryContext $context): MutationResult
    {
        $provider = $this->writer($source, DataOperation::Update);

        return $this->transaction($source, $provider, function () use ($source, $provider, $key, $input, $context): MutationResult {
            $record = $this->locked($source, $provider, $key, $context);
            $this->authorize($source, DataOperation::Update, $context, $record);

            $attributes = $this->prepare($source, DataOperation::Update, $this->validated($source, DataOperation::Update, $input, $context, $record), $record);
            $before = $provider->attributesOf($record);
            $updated = $provider->update($source, $record, $attributes);
            $after = $provider->attributesOf($updated);
            $changes = $updated instanceof Model ? $updated->getChanges() : self::changed($before, array_intersect_key($after, $attributes));
            $original = array_intersect_key($before, $changes);
            $visible = $this->visible($source, $provider, $provider->keyOf($source, $updated) ?? $key, $context);

            return $this->written($source, DataOperation::Update, $provider->keyOf($source, $updated) ?? $key, $visible, $changes, $original, $context, RecordUpdated::class);
        });
    }

    /**
     * Delete (soft delete for SoftDeletes models), or permanently delete with $force.
     *
     * @throws AuthorizationException|MutationException|RecordNotFoundException
     */
    public function delete(SourceDefinition $source, string|int $key, QueryContext $context, bool $force = false): MutationResult
    {
        $operation = $force ? DataOperation::ForceDelete : DataOperation::Delete;
        $provider = $this->writer($source, $operation);

        return $this->transaction($source, $provider, function () use ($source, $provider, $key, $context, $force, $operation): MutationResult {
            $record = $this->locked($source, $provider, $key, $context);
            $this->authorize($source, $operation, $context, $record);
            $original = $provider->attributesOf($record);
            $provider->delete($source, $record, $force);

            return $this->written($source, $operation, $provider->keyOf($source, $record) ?? $key, null, [], $original, $context, RecordDeleted::class, $record);
        });
    }

    /**
     * Restore a soft-deleted record (Eloquent models with SoftDeletes).
     *
     * @throws AuthorizationException|MutationException|RecordNotFoundException
     */
    public function restore(SourceDefinition $source, string|int $key, QueryContext $context): MutationResult
    {
        $provider = $this->writer($source, DataOperation::Restore);

        return $this->transaction($source, $provider, function () use ($source, $provider, $key, $context): MutationResult {
            $record = $provider->lock($source, $key, $this->queries->constraints($source, $context), trashed: true) ?? throw RecordNotFoundException::in($source->name);
            $this->authorize($source, DataOperation::Restore, $context, $record);
            $restored = $provider->restore($source, $record);
            $visible = $this->visible($source, $provider, $provider->keyOf($source, $restored) ?? $key, $context);

            return $this->written($source, DataOperation::Restore, $provider->keyOf($source, $restored) ?? $key, $visible, [], [], $context, RecordRestored::class);
        });
    }

    /**
     * Several operations as one atomic unit (all or nothing).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function batch(SourceDefinition $source, Closure $callback): mixed
    {
        $provider = $this->providers->for($source);

        return $this->transactions->run(static fn (): mixed => $callback(), new TransactionOptions($provider instanceof SupportsMutations ? self::connectionName($provider->connection($source)) : null));
    }

    private function writer(SourceDefinition $source, DataOperation $operation): SupportsMutations
    {
        $writable = $source->writable ?? throw MutationException::readOnly($source->name);

        if (! $writable->allows($operation)) {
            throw MutationException::operationNotAllowed($source->name, $operation->value);
        }

        $provider = $this->providers->for($source);

        if (! $provider instanceof SupportsMutations || ! $provider->writable($source) || ! $provider instanceof ResolvesRecords) {
            throw MutationException::unsupported($source->name, sprintf('the "%s" provider cannot write it.', $source->provider));
        }

        return $provider;
    }

    /**
     * @param  Closure(): MutationResult  $callback
     */
    private function transaction(SourceDefinition $source, SupportsMutations $provider, Closure $callback): MutationResult
    {
        $started = hrtime(true);
        $result = $this->transactions->run(static fn (): MutationResult => $callback(), new TransactionOptions(self::connectionName($provider->connection($source))));

        return new MutationResult($result->source, $result->operation, $result->key, $result->record, $result->changes, $result->original, round((hrtime(true) - $started) / 1e6, 3));
    }

    /**
     * Attributes whose value differs from before. Loose comparison: drivers may return numbers as strings.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private static function changed(array $before, array $after): array
    {
        return array_filter($after, static fn (mixed $value, string $field): bool => ! array_key_exists($field, $before) || $before[$field] != $value, ARRAY_FILTER_USE_BOTH);
    }

    private function locked(SourceDefinition $source, SupportsMutations $provider, string|int $key, QueryContext $context): mixed
    {
        return $provider->lock($source, $key, $this->queries->constraints($source, $context)) ?? throw RecordNotFoundException::in($source->name);
    }

    private function authorize(SourceDefinition $source, DataOperation $operation, QueryContext $context, mixed $record = null): void
    {
        if ($context->authorize) {
            $this->authorizer->authorize($source, $operation, $context, $record);
        }
    }

    /**
     * The allowlisted, permitted and validated attributes of the input.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException|AuthorizationException
     */
    private function validated(SourceDefinition $source, DataOperation $operation, array $input, QueryContext $context, mixed $record): array
    {
        /** @var Writable $writable */
        $writable = $source->writable;
        $tenant = $this->tenants->column($source);
        $errors = [];

        foreach (array_keys($input) as $field) {
            if (! is_string($field) || ! array_key_exists($field, $writable->fields) || $field === $tenant) {
                $errors[(string) $field] = sprintf('The %s field is not writable.', mb_substr((string) $field, 0, 64));
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, mixed> $input */
        $forbidden = array_values(array_intersect(array_keys($input), $this->fields->unwritable($source, $context, $record)));

        if ($forbidden !== []) {
            throw new AuthorizationException(sprintf('You may not change the %s field%s.', implode(', ', $forbidden), count($forbidden) > 1 ? 's' : ''));
        }

        $validated = $this->validation->make($input, $writable->rules($operation, $record))->validate();

        return [...$input, ...array_intersect_key($validated, $input)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(SourceDefinition $source, DataOperation $operation, array $attributes, mixed $record): array
    {
        foreach ($source->beforeSave as $hook) {
            $attributes = $hook->prepare($attributes, $operation, $record);
        }

        $tenant = $this->tenants->column($source);

        if ($tenant !== null) {
            unset($attributes[$tenant]);
        }

        return $attributes;
    }

    /**
     * The written record as the source sees it; a write may not move it out of scope.
     *
     * @throws MutationException
     */
    private function visible(SourceDefinition $source, SupportsMutations $provider, string|int|null $key, QueryContext $context): mixed
    {
        $record = $key === null || ! $provider instanceof ResolvesRecords ? null : $provider->find($source, $key, $this->queries->constraints($source, $context));

        return $record ?? throw MutationException::outOfScope($source->name);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $original
     * @param  class-string<RecordWritten>  $event
     */
    private function written(SourceDefinition $source, DataOperation $operation, string|int|null $key, mixed $record, array $changes, array $original, QueryContext $context, string $event, mixed $eventRecord = null): MutationResult
    {
        $user = $this->queries->user($context);
        $tenant = $this->tenants->column($source) === null ? null : $this->tenants->tenant($context);
        $payload = new $event($source->name, $operation, $key, $eventRecord ?? $record, $changes, $original, $user, $tenant);
        $dependencies = $this->cache->dependencies($source);

        $this->afterCommit($source, function () use ($dependencies, $payload): void {
            $this->cache->invalidate(...$dependencies);
            $this->events->dispatch($payload);
        });

        $presented = $record === null ? null : $this->queries->present($source, $record, $this->fields->unreadable($source, $context, $record));

        return new MutationResult($source->name, $operation, $key, $presented, $changes, $original);
    }

    private function afterCommit(SourceDefinition $source, Closure $callback): void
    {
        $provider = $this->providers->for($source);
        $connection = $provider instanceof SupportsMutations ? $provider->connection($source) : null;

        if ($connection !== null && method_exists($connection, 'afterCommit')) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    private static function connectionName(ConnectionInterface $connection): ?string
    {
        $name = $connection instanceof Connection ? $connection->getName() : null;

        return is_string($name) ? $name : null;
    }
}
