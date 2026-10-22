<?php

declare(strict_types=1);

namespace Gcego\Data;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\BuilderFactory;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Provider;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * A declarative, immutable data-source definition:
 *
 *     return Source::define(
 *         Name::make('employees'),
 *         Model::make(Employee::class),
 *         Constraints::make([Filter::make('is_active', true)]),
 *         Filterables::make([
 *             Filterable::make('age', [Integer::make(), GreaterThanOrEqual::make(), LessThan::make()]),
 *             Filterable::make('status', [StringType::make(), Equal::make(), In::make()]),
 *         ]),
 *         Search::make(['name', 'email']),
 *         Sort::make('created_at', 'desc'),
 *         Sortables::make(['name', 'created_at']),
 *         Pagination::make(25),
 *     );
 *
 * Each definition class may appear once. Exactly one origin is required:
 * Model, DatabaseTable, BuilderFactory or Items (or a custom Provider).
 * Defining a source never touches the database or autoloads model classes;
 * SourceCompiler checks references when the source is first used, and
 * DataManager executes it.
 */
final class Source
{
    /** @var list<class-string<SourceConfiguration>> */
    public const array Origins = [Model::class, DatabaseTable::class, BuilderFactory::class, Items::class];

    /**
     * @param  array<class-string<SourceConfiguration>, SourceConfiguration>  $definitions
     */
    private function __construct(private readonly array $definitions) {}

    /**
     * @throws InvalidSourceException for duplicate definitions, a missing origin or several origins.
     */
    public static function define(SourceConfiguration ...$definitions): self
    {
        $byClass = [];

        foreach ($definitions as $definition) {
            $class = $definition::class;

            if (isset($byClass[$class])) {
                throw InvalidSourceException::invalidDefinition('Source', sprintf('%s is declared twice.', class_basename($class)));
            }

            $byClass[$class] = $definition;
        }

        $origins = array_values(array_filter(self::Origins, static fn (string $origin): bool => isset($byClass[$origin])));

        if (count($origins) > 1) {
            throw InvalidSourceException::invalidDefinition('Source', sprintf('declare only one of Model, DatabaseTable, BuilderFactory or Items (found %s).', implode(', ', array_map(class_basename(...), $origins))));
        }

        if ($origins === [] && ! isset($byClass[Provider::class])) {
            throw InvalidSourceException::invalidDefinition('Source', 'declare where the data comes from: Model::make(...), DatabaseTable::make(...), BuilderFactory::make(...), Items::make(...) or a custom Provider::make(...).');
        }

        return new self($byClass);
    }

    /**
     * A copy with these definitions added or replaced (one per class).
     *
     * @throws InvalidSourceException
     */
    public function with(SourceConfiguration ...$definitions): self
    {
        $replaced = array_map(static fn (SourceConfiguration $definition): string => $definition::class, $definitions);

        return self::define(...array_values(array_diff_key($this->definitions, array_flip($replaced))), ...$definitions);
    }

    /**
     * A copy without these definition classes.
     *
     * @param  class-string<SourceConfiguration>  ...$classes
     *
     * @throws InvalidSourceException
     */
    public function without(string ...$classes): self
    {
        return self::define(...array_values(array_diff_key($this->definitions, array_flip($classes))));
    }

    /**
     * The definition of this class, if declared.
     *
     * @template T of SourceConfiguration
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function get(string $class): ?SourceConfiguration
    {
        $definition = $this->definitions[$class] ?? null;

        return $definition instanceof $class ? $definition : null;
    }

    /**
     * @param  class-string<SourceConfiguration>  $class
     */
    public function has(string $class): bool
    {
        return isset($this->definitions[$class]);
    }

    /**
     * @return list<SourceConfiguration> In declaration order.
     */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    /**
     * The declared Name, if any (SourceDefinition::$name always has one).
     */
    public function name(): ?string
    {
        return $this->get(Name::class)?->value;
    }

    /**
     * A plain description without closures, safe for diagnostics and caches.
     *
     * @return array{definitions: array<string, array<string, mixed>>}
     */
    public function normalize(): array
    {
        $normalized = [];

        foreach ($this->definitions as $class => $definition) {
            $normalized[$class] = $definition->normalize();
        }

        return ['definitions' => $normalized];
    }
}
