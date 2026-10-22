<?php

declare(strict_types=1);

namespace Gcego\Data;

use BackedEnum;
use Closure;
use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\Callback;
use Gcego\Data\Support\FieldName;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A declarative data-source definition.
 *
 *     new Source(Subject::class);
 *
 *     new Source(new Property(Source::Model, Subject::class), new Property(Source::Pagination, 25));
 *
 *     new Source(
 *         Subject::class,
 *         new Filter('is_published', true),
 *         new Sort('name', 'asc'),
 *         new Paginate(25),
 *     );
 *
 *     Source::table('report_rows', new Sortable('total'));
 *     Source::collection(fn () => config('school.terms'));
 *
 * A Source only describes data. Constructing one never touches the database
 * and never validates references; queries run when DataManager (or a package
 * built on it) executes it, and SourceCompiler validates it first.
 * A Source is immutable: the with*() methods return copies.
 */
final class Source
{
    /** Logical name, used in diagnostics, errors and the query-builder ability. */
    public const string Name = 'name';

    /** Eloquent model class (the positional shorthand). */
    public const string Model = 'model';

    /** Provider name: "eloquent", "query", "collection" or a registered custom provider. Inferred when omitted. */
    public const string Provider = 'provider';

    /** Database table of a query-builder source. */
    public const string Table = 'table';

    /** Database connection name. Eloquent sources default to the model's connection. */
    public const string Connection = 'connection';

    /** Unique key of a query-builder or collection source (default "id"). Eloquent uses the model's key. */
    public const string Key = 'key';

    /** Columns selected by a query-builder source (default all). */
    public const string Columns = 'columns';

    /** Trusted factory (Callback) returning a query builder, for query-builder sources. */
    public const string Builder = 'builder';

    /** Trusted factory (Callback) returning the items of a collection source. */
    public const string Items = 'items';

    /** Trusted constraint (Callback), equivalent to a Query node. */
    public const string Query = 'query';

    /** Fixed equality conditions: ['is_published' => true], equivalent to Filter nodes. */
    public const string Filters = 'filters';

    /** Searchable fields, equivalent to a Search node. */
    public const string Search = 'search';

    /** Default ordering: ['name', '-created_at'], equivalent to Sort nodes. */
    public const string Sort = 'sort';

    /** Page size, equivalent to new Paginate($size). */
    public const string Pagination = 'pagination';

    /** Always eager-loaded relations, equivalent to a With node. */
    public const string Relationships = 'relationships';

    public const string Type = 'data.source';

    public const string NamePattern = '/\A[a-z][a-z0-9_.\-]{0,63}\z/';

    public const array BuiltInProviders = ['eloquent', 'query', 'collection'];

    /**
     * @var array<string, Property>
     */
    private array $properties = [];

    /**
     * @var list<SourceConfiguration>
     */
    private array $configurations = [];

    /**
     * @param  string|Property|SourceConfiguration  ...$items  An optional leading model class, then properties and configuration nodes.
     *
     * @throws InvalidSourceException when a property is declared twice or an item is misplaced.
     */
    public function __construct(string|Property|SourceConfiguration ...$items)
    {
        foreach (array_values($items) as $position => $item) {
            match (true) {
                is_string($item) && $position === 0 => $this->addProperty(new Property(self::Model, $item)),
                is_string($item) => throw InvalidSourceException::invalidDefinition('Source', 'only the first argument may be a model class; use new Property(...) for other settings.'),
                $item instanceof Property => $this->addProperty($item),
                default => $this->configurations[] = $item,
            };
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function model(string $model, Property|SourceConfiguration ...$items): self
    {
        return new self($model, ...$items);
    }

    public static function table(string $table, Property|SourceConfiguration ...$items): self
    {
        return new self(new Property(self::Table, $table), ...$items);
    }

    /**
     * A query-builder source from a trusted factory, called for every execution:
     *
     *     Source::builder(fn () => DB::table('enrolments')->join(...)->select(...), new Property(Source::Key, 'enrolments.id'))
     */
    public static function builder(Closure $factory, Property|SourceConfiguration ...$items): self
    {
        return new self(new Property(self::Builder, new Callback($factory)), ...$items);
    }

    /**
     * An in-memory source. Suitable for small, bounded datasets: every execution loads all items.
     *
     * @param  Closure(): iterable<mixed>|iterable<mixed>  $items
     */
    public static function collection(Closure|iterable $items, Property|SourceConfiguration ...$configuration): self
    {
        $factory = $items instanceof Closure ? $items : static fn (): iterable => $items;

        return new self(new Property(self::Items, new Callback($factory)), ...$configuration);
    }

    /**
     * The explicit name, if one was declared (SourceDefinition::$name always has one).
     */
    public function name(): ?string
    {
        $name = $this->get(self::Name);

        return is_string($name) ? $name : null;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return isset($this->properties[$key]) ? $this->properties[$key]->value : $fallback;
    }

    public function has(string $key): bool
    {
        return isset($this->properties[$key]);
    }

    /**
     * @return array<string, Property> In declaration order.
     */
    public function properties(): array
    {
        return $this->properties;
    }

    /**
     * @return list<SourceConfiguration> In declaration order.
     */
    public function configurations(): array
    {
        return $this->configurations;
    }

    /**
     * The first configuration node of the given class.
     *
     * @template T of SourceConfiguration
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function configuration(string $class): ?SourceConfiguration
    {
        foreach ($this->configurations as $configuration) {
            if ($configuration instanceof $class) {
                return $configuration;
            }
        }

        return null;
    }

    /**
     * @param  class-string<SourceConfiguration>  $class
     */
    public function hasConfiguration(string $class): bool
    {
        return $this->configuration($class) !== null;
    }

    /**
     * A copy with the given properties set (replacing existing values).
     */
    public function withProperty(Property ...$properties): self
    {
        $copy = clone $this;

        foreach ($properties as $property) {
            $copy->properties[$property->key] = $property;
        }

        return $copy;
    }

    /**
     * A copy with the given configuration nodes appended.
     */
    public function withConfigurations(SourceConfiguration ...$configurations): self
    {
        $copy = clone $this;
        $copy->configurations = [...$copy->configurations, ...array_values($configurations)];

        return $copy;
    }

    /**
     * A copy with the given name.
     */
    public function named(string $name): self
    {
        return $this->withProperty(new Property(self::Name, $name));
    }

    /**
     * Structural problems, as readable messages (empty when valid). Pure: no
     * database access; model classes are autoloaded to check them.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];

        foreach ($this->properties as $key => $property) {
            $rule = self::rules()[$key] ?? null;

            if ($rule === null) {
                $errors[] = sprintf('Unknown property "%s". Known properties: %s.', $key, implode(', ', array_keys(self::rules())));
            } elseif (! $rule[0]($property->value)) {
                $errors[] = sprintf('Invalid property "%s". Expected: %s.', $key, $rule[1]);
            }
        }

        $origins = array_values(array_filter([self::Model, self::Table, self::Builder, self::Items], $this->has(...)));
        $custom = $this->has(self::Provider) && ! in_array($this->get(self::Provider), self::BuiltInProviders, true);

        $errors[] = match (true) {
            count($origins) > 1 => sprintf('declare only one of model, table, builder or items (found %s).', implode(', ', $origins)),
            $origins === [] && ! $custom => 'declare a model class (new Source(Subject::class)), a table, a builder or items.',
            default => null,
        };

        return array_values(array_filter($errors, is_string(...)));
    }

    /**
     * A plain description without closures, safe for diagnostics and caches.
     *
     * @return array{type: string, properties: array<string, mixed>, configurations: list<array<string, mixed>>}
     */
    public function normalize(): array
    {
        return [
            'type' => self::Type,
            'properties' => array_map(static fn (Property $property): mixed => self::normalizeValue($property->value), $this->properties),
            'configurations' => array_map(static fn (SourceConfiguration $configuration): array => ['type' => $configuration::class, ...$configuration->normalize()], $this->configurations),
        ];
    }

    private function addProperty(Property $property): void
    {
        if (isset($this->properties[$property->key])) {
            throw InvalidSourceException::invalidDefinition('Source', sprintf('property "%s" is declared twice.', $property->key));
        }

        $this->properties[$property->key] = $property;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Callback => ['runtime' => Callback::class],
            $value instanceof UnitEnum => $value instanceof BackedEnum ? $value->value : $value->name,
            is_array($value) => array_map(self::normalizeValue(...), $value),
            default => $value,
        };
    }

    /**
     * @return array<string, array{0: Closure(mixed): bool, 1: string}>
     */
    private static function rules(): array
    {
        $field = static fn (mixed $value): bool => is_string($value) && FieldName::isValid($value);
        $fields = static fn (mixed $value): bool => is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => ! $field($item)) === [];
        $callback = static fn (mixed $value): bool => $value instanceof Callback;

        return [
            self::Name => [static fn (mixed $value): bool => is_string($value) && preg_match(self::NamePattern, $value) === 1, 'lowercase name of letters, digits, "_", "." or "-"'],
            self::Model => [static fn (mixed $value): bool => is_string($value) && class_exists($value) && is_subclass_of($value, Model::class), 'Eloquent model class'],
            self::Provider => [static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $value) === 1, 'provider name'],
            self::Table => [$field, 'table name'],
            self::Connection => [static fn (mixed $value): bool => is_string($value) && $value !== '', 'connection name'],
            self::Key => [$field, 'column name'],
            self::Columns => [$fields, 'non-empty list of column names'],
            self::Builder => [$callback, Callback::class],
            self::Items => [$callback, Callback::class],
            self::Query => [$callback, Callback::class],
            self::Filters => [static fn (mixed $value): bool => is_array($value) && $value !== [] && ! array_is_list($value), 'map of field => value'],
            self::Search => [$fields, 'non-empty list of field names'],
            self::Sort => [static fn (mixed $value): bool => is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => ! is_string($item) || ! $field(ltrim($item, '-'))) === [], 'list of fields, "-" prefix for descending'],
            self::Pagination => [static fn (mixed $value): bool => is_int($value) && $value >= 1, 'positive page size'],
            self::Relationships => [$fields, 'non-empty list of relation paths'],
        ];
    }
}
