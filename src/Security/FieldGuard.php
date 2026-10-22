<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

use Closure;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Query\QueryContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Evaluates FieldAccess rules for one call and redacts records.
 *
 * Rules are evaluated for the call's user. Listings evaluate them once
 * against the model class (record null); record operations against the record.
 * System contexts (authorize: false) are subject to false rules only: an
 * ability or closure needs a user to grant anything, but trusted code is
 * exempt from them.
 */
final readonly class FieldGuard
{
    public function __construct(private Gate $gate, private Container $container) {}

    /**
     * Fields this call may not read.
     *
     * @return list<string>
     */
    public function unreadable(SourceDefinition $source, QueryContext $context, mixed $record = null): array
    {
        return $this->denied($source, $context, $record, 'read');
    }

    /**
     * Fields this call may not write.
     *
     * @return list<string>
     */
    public function unwritable(SourceDefinition $source, QueryContext $context, mixed $record = null): array
    {
        return $this->denied($source, $context, $record, 'write');
    }

    /**
     * The record without the given fields. Models lose the attributes and hide
     * them (a model returned by gcego/data is never shared), so neither
     * serialization nor a transform can reach them; arrays and objects are
     * copied. Dotted fields ("manager.salary") are removed from the loaded
     * related records (every record of a to-many relation).
     *
     * @param  list<string>  $fields
     */
    public static function redact(mixed $record, array $fields): mixed
    {
        if ($fields === []) {
            return $record;
        }

        $nested = [];

        foreach ($fields as $index => $field) {
            if (str_contains($field, '.')) {
                [$relation, $rest] = explode('.', $field, 2);
                $nested[$relation][] = $rest;
                unset($fields[$index]);
            }
        }

        $fields = array_values($fields);

        foreach ($nested as $relation => $inner) {
            $record = self::redactRelation($record, $relation, $inner);
        }

        if ($fields === []) {
            return $record;
        }

        if ($record instanceof Model) {
            $record->setRawAttributes(array_diff_key($record->getAttributes(), array_flip($fields)), true);

            return $record->makeHidden($fields);
        }

        if (is_array($record)) {
            return array_diff_key($record, array_flip($fields));
        }

        if ($record instanceof Arrayable) {
            return array_diff_key($record->toArray(), array_flip($fields));
        }

        if (is_object($record)) {
            $copy = clone $record;

            foreach ($fields as $field) {
                unset($copy->{$field});
            }

            return $copy;
        }

        return $record;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function redactRelation(mixed $record, string $relation, array $fields): mixed
    {
        $redactAll = static function (mixed $related) use ($fields): mixed {
            if ($related instanceof EloquentCollection) {
                return $related->map(static fn (mixed $item): mixed => self::redact($item, $fields));
            }

            return is_array($related) && array_is_list($related)
                ? array_map(static fn (mixed $item): mixed => self::redact($item, $fields), $related)
                : self::redact($related, $fields);
        };

        if ($record instanceof Model) {
            if ($record->relationLoaded($relation)) {
                $record->setRelation($relation, $redactAll($record->getRelation($relation)));
            }

            return $record;
        }

        if (is_array($record) && array_key_exists($relation, $record)) {
            $record[$relation] = $redactAll($record[$relation]);
        }

        return $record;
    }

    /**
     * @return list<string>
     */
    private function denied(SourceDefinition $source, QueryContext $context, mixed $record, string $mode): array
    {
        $denied = [];

        foreach ($source->fieldAccess as $field => $access) {
            $rule = $mode === 'read' ? $access->read : $access->write;

            if (! $this->allows($rule, $source, $context, $record)) {
                $denied[] = $field;
            }
        }

        return $denied;
    }

    /**
     * @param  bool|string|Closure(mixed, mixed): bool|null  $rule
     */
    private function allows(bool|string|Closure|null $rule, SourceDefinition $source, QueryContext $context, mixed $record): bool
    {
        if ($rule === null || $rule === true) {
            return true;
        }

        if ($rule === false) {
            return false;
        }

        if (! $context->authorize) {
            return true;
        }

        if ($rule instanceof Closure) {
            return $rule($context->user ?? $this->currentUser(), $record) === true;
        }

        $gate = $context->user === null ? $this->gate : $this->gate->forUser($context->user);

        return $gate->allows($rule, [is_object($record) ? $record : ($source->model ?? $source->name)]);
    }

    private function currentUser(): ?Authenticatable
    {
        return $this->container->bound(AuthFactory::class) ? $this->container->make(AuthFactory::class)->guard()->user() : null;
    }
}
