<?php

declare(strict_types=1);

namespace Gcego\Data\Compilation;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\Aggregates;
use Gcego\Data\Definitions\BeforeSave;
use Gcego\Data\Definitions\BuilderFactory;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Definitions\Columns;
use Gcego\Data\Definitions\Connection;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\FieldSecurity;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Key;
use Gcego\Data\Definitions\Limits;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Provider;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Definitions\TenantScoped;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Definitions\Writable;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Source;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Validates a Source's references and cross-definition rules, and compiles its
 * definitions into one SourceDefinition. Pure: no database access, no
 * container lookups. Results are cached per Source instance (Sources are
 * immutable). Provider-specific checks (relations, columns) run when the
 * provider prepares the definition.
 */
final class SourceCompiler
{
    public const array BuiltIn = [
        Name::class, Model::class, DatabaseTable::class, BuilderFactory::class, Items::class, Provider::class,
        Connection::class, Key::class, Columns::class, Constraints::class, Filterables::class, Search::class,
        Sort::class, Sortables::class, Pagination::class, Relations::class, Includables::class, Aggregates::class,
        Transform::class, Writable::class, BeforeSave::class, TenantScoped::class, FieldSecurity::class,
        Cached::class, Limits::class,
    ];

    /**
     * @var WeakMap<Source, SourceDefinition>
     */
    private WeakMap $compiled;

    public function __construct()
    {
        $this->compiled = new WeakMap;
    }

    /**
     * @throws InvalidSourceException
     */
    public function compile(Source $source): SourceDefinition
    {
        return $this->compiled[$source] ??= $this->build($source);
    }

    private function build(Source $source): SourceDefinition
    {
        $model = $source->get(Model::class)?->class;
        $table = $source->get(DatabaseTable::class)?->name;
        $name = $source->name() ?? match (true) {
            $model !== null => Str::snake(Str::pluralStudly(class_basename($model))),
            $table !== null => $table,
            $source->has(Items::class) => 'collection',
            default => 'query',
        };

        if ($model !== null && (! class_exists($model) || ! is_subclass_of($model, EloquentModel::class))) {
            throw InvalidSourceException::invalid($name, sprintf('"%s" is not an Eloquent model class.', $model));
        }

        $filterables = $source->get(Filterables::class);
        $aggregates = ($source->get(Aggregates::class)->aggregates ?? []);
        $tenant = $source->get(TenantScoped::class);
        $writable = $source->get(Writable::class);
        $sortables = ($source->get(Sortables::class)->columns ?? []);

        if ($tenant !== null && $writable !== null && array_key_exists($tenant->column, $writable->fields)) {
            throw InvalidSourceException::invalid($name, sprintf('the tenant column "%s" cannot be writable; it is stamped from the current tenant.', $tenant->column));
        }

        if ($source->has(Cached::class) && $source->name() === null) {
            throw InvalidSourceException::invalid($name, 'cached sources need a unique Name::make(...): it identifies them in cache keys.');
        }

        if ($model !== null && ($source->has(Columns::class) || $source->has(Key::class))) {
            throw InvalidSourceException::invalid($name, 'model sources use the model\'s key and attributes; Columns and Key are for table, builder and collection sources (use $hidden, FieldSecurity or Transform).');
        }

        $transform = $source->get(Transform::class);
        $beforeSave = $source->get(BeforeSave::class);
        $constraints = $source->get(Constraints::class);

        return new SourceDefinition(
            name: $name,
            provider: $this->provider($source),
            source: $source,
            model: $model,
            table: $table,
            connection: $source->get(Connection::class)?->name,
            key: $source->get(Key::class)?->column,
            columns: $source->get(Columns::class)?->names,
            builder: $source->get(BuilderFactory::class)?->factory,
            items: $source->get(Items::class)?->factory,
            constraints: $constraints === null ? [] : $constraints->queries,
            filters: $constraints === null ? [] : $constraints->filters,
            filterables: $filterables === null ? [] : $filterables->filterables,
            search: $source->get(Search::class),
            sorts: ($source->get(Sort::class)->orders ?? []),
            sortables: $sortables,
            pagination: $source->get(Pagination::class),
            with: ($source->get(Relations::class)->paths ?? []),
            includables: ($source->get(Includables::class)->paths ?? []),
            transforms: $transform === null ? [] : [$transform],
            extensions: array_values(array_filter($source->definitions(), static fn (SourceConfiguration $definition): bool => ! in_array($definition::class, self::BuiltIn, true))),
            customFilters: $filterables === null ? [] : $filterables->custom,
            aggregates: $aggregates,
            tenant: $tenant,
            fieldAccess: ($source->get(FieldSecurity::class)->fields ?? []),
            writable: $writable,
            beforeSave: $beforeSave === null ? [] : [$beforeSave],
            cache: $source->get(Cached::class),
            limits: $source->get(Limits::class),
        );
    }

    private function provider(Source $source): string
    {
        return match (true) {
            $source->has(Provider::class) => $source->get(Provider::class)->name ?? 'query',
            $source->has(Model::class) => 'eloquent',
            $source->has(Items::class) => 'collection',
            default => 'query',
        };
    }
}
