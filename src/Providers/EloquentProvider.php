<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\RelationOperator;
use Gcego\Data\Contracts\SupportsRelationships;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Exceptions\MutationException;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Relationships\RelationPathValidator;
use Gcego\Data\Tenancy\RelationScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;

/**
 * Eloquent models: $model->newQuery() keeps global scopes (tenancy, soft
 * deletes, ...) and the model's connection unless Connection::make() is declared.
 * Keys follow the model: integer, UUID, ULID or custom primary keys.
 * Records are model instances, so $hidden, $visible, casts and accessors apply.
 *
 * Writes go through the model (forceFill() with the allowlisted, validated
 * attributes, then save()), so casts, mutators, timestamps, model events and
 * observers apply; soft-deleting models soft delete unless forced.
 */
final class EloquentProvider extends BuilderProvider implements SupportsRelationships
{
    protected function validate(SourceDefinition $source): void
    {
        $model = $source->model;

        if ($model === null || ! is_subclass_of($model, Model::class) || (new ReflectionClass($model))->isAbstract()) {
            throw InvalidSourceException::invalidModel((string) $model);
        }

        if ($source->columns !== null) {
            throw InvalidSourceException::invalid($source->name, 'Eloquent sources do not select columns; use the model\'s $hidden/$visible or Transform::only().');
        }

        $paths = [...array_keys($source->with), ...array_keys($source->includables)];
        $fields = [...($source->search->fields ?? [])];

        foreach ($source->filterables as $filterable) {
            $filterable->isRelation() ? $paths[] = $filterable->column : $fields[] = $filterable->column;
        }

        foreach ($source->filters as $filter) {
            $filter->operator instanceof RelationOperator ? $paths[] = $filter->field : $fields[] = $filter->field;
        }

        foreach ($fields as $field) {
            if (str_contains($field, '.')) {
                $paths[] = substr($field, 0, (int) strrpos($field, '.'));
            }
        }

        foreach ($source->aggregates as $aggregate) {
            $paths[] = $aggregate->relation;
        }

        foreach (array_unique($paths) as $path) {
            RelationPathValidator::assert($source->name, $model, $path);
        }

        if ($source->pagination->mode === PaginationMode::Cursor && array_intersect($source->sortables, array_keys($source->aggregates)) !== []) {
            throw InvalidSourceException::invalid($source->name, 'cursor pagination cannot sort by a relationship aggregate; use page pagination.');
        }
    }

    protected function newQuery(SourceDefinition $source): EloquentBuilder
    {
        return $this->model($source)->newQuery();
    }

    protected function keyColumn(EloquentBuilder|QueryBuilder $query, SourceDefinition $source): string
    {
        return $query instanceof EloquentBuilder ? $query->getModel()->getQualifiedKeyName() : 'id';
    }

    protected function normalizeKey(SourceDefinition $source, string|int $key): string|int|null
    {
        /** @var class-string<Model> $class */
        $class = $source->model;
        $model = new $class;

        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return is_int($key) ? $key : (preg_match('/^-?\d{1,18}$/', $key) === 1 ? (int) $key : null);
        }

        $key = (string) $key;

        if (in_array(HasUniqueStringIds::class, class_uses_recursive($model), true)) {
            // HasUuids/HasUlids decide what a valid key looks like; the method is protected.
            $valid = (fn (string $value): mixed => $this->isValidUniqueId($value))->call($model, $key); // @phpstan-ignore method.notFound (protected method of HasUniqueStringIds, which the check above guarantees)

            return $valid === true ? $key : null;
        }

        return $key !== '' && strlen($key) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $key) !== 1 ? $key : null;
    }

    protected function loadAggregates(EloquentBuilder|QueryBuilder $query, array $aggregates): void
    {
        if (! $query instanceof EloquentBuilder) {
            return;
        }

        foreach ($aggregates as $aggregate) {
            $relation = [$aggregate->relation.' as '.$aggregate->alias => RelationScope::wrap($aggregate->constraint)];

            match ($aggregate->eloquentFunction()) {
                'count' => $query->withCount($relation),
                'exists' => $query->withExists($relation),
                default => $query->withAggregate($relation, (string) $aggregate->column, $aggregate->eloquentFunction()),
            };
        }
    }

    /**
     * Eager load every path, with the tenant scope on every level of it (a
     * nested path also constrains its intermediate relations).
     */
    protected function load(EloquentBuilder|QueryBuilder $query, array $relations): void
    {
        if (! $query instanceof EloquentBuilder || $relations === []) {
            return;
        }

        $levels = [];

        foreach ($relations as $path => $constraint) {
            $prefix = '';

            foreach (explode('.', $path) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix.'.'.$segment;
                $levels[$prefix] ??= [];

                if ($prefix === $path && $constraint !== null) {
                    $levels[$prefix][] = $constraint;
                }
            }
        }

        $with = [];

        foreach ($levels as $path => $constraints) {
            $with[$path] = RelationScope::wrap($constraints === [] ? null : static function (mixed $related) use ($constraints): void {
                foreach ($constraints as $constraint) {
                    $constraint($related);
                }
            });
        }

        $query->with($with);
    }

    public function writable(SourceDefinition $source): bool
    {
        return true;
    }

    public function connection(SourceDefinition $source): ConnectionInterface
    {
        return $this->model($source)->getConnection();
    }

    public function create(SourceDefinition $source, array $attributes): Model
    {
        $model = $this->model($source);
        $model->forceFill($attributes)->save();

        return $model;
    }

    public function update(SourceDefinition $source, mixed $record, array $attributes): Model
    {
        $model = $this->record($source, $record);
        $model->forceFill($attributes)->save();

        return $model;
    }

    public function delete(SourceDefinition $source, mixed $record, bool $force = false): void
    {
        $model = $this->record($source, $record);
        $force ? $model->forceDelete() : $model->delete();
    }

    public function restore(SourceDefinition $source, mixed $record): Model
    {
        $model = $this->record($source, $record);

        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            throw MutationException::unsupported($source->name, 'only models with SoftDeletes can be restored.');
        }

        $model->restore(); // @phpstan-ignore method.notFound (SoftDeletes, checked above)

        return $model;
    }

    public function keyOf(SourceDefinition $source, mixed $record): string|int|null
    {
        $key = $record instanceof Model ? $record->getKey() : null;

        return is_int($key) || is_string($key) ? $key : null;
    }

    public function attributesOf(mixed $record): array
    {
        return $record instanceof Model ? $record->getAttributes() : [];
    }

    protected function onlyTrashed(EloquentBuilder|QueryBuilder $query, SourceDefinition $source): void
    {
        if (! $query instanceof EloquentBuilder || ! in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            throw MutationException::unsupported($source->name, 'only models with SoftDeletes can be restored.');
        }

        $query->onlyTrashed(); // @phpstan-ignore method.notFound (SoftDeletes macro, checked above)
    }

    private function model(SourceDefinition $source): Model
    {
        /** @var class-string<Model> $class */
        $class = $source->model;
        $model = new $class;

        if ($source->connection !== null) {
            $model->setConnection($source->connection);
        }

        return $model;
    }

    private function record(SourceDefinition $source, mixed $record): Model
    {
        return $record instanceof Model ? $record : throw MutationException::unsupported($source->name, sprintf('expected a %s record.', (string) $source->model));
    }
}
