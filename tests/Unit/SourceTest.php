<?php

declare(strict_types=1);

use Gcego\Data\Compilation\SourceCompiler;
use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Definitions\Aggregates;
use Gcego\Data\Definitions\Cached;
use Gcego\Data\Definitions\Columns;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Between;
use Gcego\Data\Filters\Operators\Contains;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\GreaterThan;
use Gcego\Data\Filters\Operators\GreaterThanOrEqual;
use Gcego\Data\Filters\Operators\Has;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\IsNull;
use Gcego\Data\Filters\Operators\LessThan;
use Gcego\Data\Filters\Options;
use Gcego\Data\Filters\Types\Date;
use Gcego\Data\Filters\Types\Decimal;
use Gcego\Data\Filters\Types\Enum;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\Json;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Filters\Types\Time;
use Gcego\Data\Filters\Types\Uuid;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Query\Query;
use Gcego\Data\Relationships\RelationAggregate;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\SubjectLevel;

function compile(Source $source): SourceDefinition
{
    return (new SourceCompiler)->compile($source);
}

it('defines a basic model source', function (): void {
    $definition = compile(Source::define(Model::make(Subject::class)));

    expect($definition->model)->toBe(Subject::class)
        ->and($definition->provider)->toBe('eloquent')
        ->and($definition->name)->toBe('subjects')
        ->and($definition->pagination)->toEqual(Pagination::make());
});

it('defines a complete source with typed definitions', function (): void {
    $definition = compile(Source::define(
        Name::make('employees'),
        Model::make(Subject::class),
        Constraints::make([Filter::make('is_published', true)]),
        Filterables::make([
            Filterable::make('age', [Integer::make(), GreaterThanOrEqual::make(), LessThan::make()]),
            Filterable::make('salary', [Decimal::make(), GreaterThan::make(), Between::make()]),
            Filterable::make('status', [StringType::make(), Equal::make(), In::make()]),
            Filterable::make('created_at', [Date::make(), Between::make()]),
        ]),
        Search::make(['name', 'code']),
        Sort::make('created_at', 'desc'),
        Sortables::make(['name', 'created_at']),
        Pagination::make(25),
    ));

    expect($definition->name)->toBe('employees')
        ->and(array_keys($definition->filterables))->toBe(['age', 'salary', 'status', 'created_at'])
        ->and($definition->filterables['age']->operatorNames())->toBe(['gte', 'lt'])
        ->and($definition->filterables['age']->type?->name())->toBe('integer')
        ->and($definition->filters[0]->normalize())->toBe(['field' => 'is_published', 'operator' => 'eq', 'value' => true])
        ->and($definition->search?->fields)->toBe(['name', 'code'])
        ->and($definition->sorts)->toBe([['field' => 'created_at', 'direction' => SortDirection::Descending]])
        ->and($definition->sortables)->toBe(['name' => 'name', 'created_at' => 'created_at'])
        ->and($definition->pagination->perPage)->toBe(25);
});

it('defaults a filterable without operators to Equal only', function (): void {
    expect(Filterable::make('age', [Integer::make()])->operatorNames())->toBe(['eq']);
});

it('defines every origin and pagination mode', function (): void {
    expect(compile(Source::define(DatabaseTable::make('report_rows'), Columns::make(['id', 'total'])))->provider)->toBe('query')
        ->and(compile(Source::define(Items::make([['id' => 1]])))->provider)->toBe('collection')
        ->and(compile(Source::define(Model::make(Subject::class), Pagination::simple(5)))->pagination->mode)->toBe(PaginationMode::Simple)
        ->and(compile(Source::define(Model::make(Subject::class), Pagination::cursor(5)))->pagination->mode)->toBe(PaginationMode::Cursor)
        ->and(compile(Source::define(Model::make(Subject::class), Sort::make(['level' => 'desc', 'name' => 'asc'])))->sorts[1])->toBe(['field' => 'name', 'direction' => SortDirection::Ascending]);
});

it('is immutable and never queries while being defined or compiled', function (): void {
    $queries = queriesDuring(function () use (&$source, &$copy): void {
        $source = Source::define(Model::make(Subject::class), Constraints::make([Filter::make('is_published', true)]));
        $copy = $source->with(Pagination::make(5), Name::make('catalogue'));
        compile($source);
        compile($copy);
    });

    expect($queries)->toBe([])
        ->and($source->has(Pagination::class))->toBeFalse()
        ->and($source->name())->toBeNull()
        ->and($copy->get(Pagination::class)?->perPage)->toBe(5)
        ->and($copy->name())->toBe('catalogue')
        ->and($copy->without(Pagination::class)->has(Pagination::class))->toBeFalse()
        ->and(fn () => $source->get(Constraints::class)->filters[0]->field = 'x')->toThrow(Error::class); // @phpstan-ignore property.readOnlyAssignOutOfClass, property.nonObject
});

it('caches the compiled definition per source instance', function (): void {
    $compiler = new SourceCompiler;
    $source = Source::define(Model::make(Subject::class));

    expect($compiler->compile($source))->toBe($compiler->compile($source))
        ->and($compiler->compile(Source::define(Model::make(Subject::class))))->not->toBe($compiler->compile($source));
});

it('normalizes without exposing closures', function (): void {
    $source = Source::define(Model::make(Subject::class), Constraints::make([Query::make(fn ($query) => $query->where('secret', 1))]), Relations::make(['topics' => fn ($query) => $query]));
    $json = (string) json_encode($source->normalize());

    expect($json)->toContain('"queries":["closure"]')
        ->and($json)->toContain('"relations":["topics"]')
        ->and($json)->not->toContain('secret');
});

it('rejects invalid and conflicting source definitions', function (Closure $define, string $message): void {
    expect($define)->toThrow(InvalidSourceException::class, $message);
})->with([
    'no origin' => [fn () => Source::define(Pagination::make(5)), 'declare where the data comes from'],
    'two origins' => [fn () => Source::define(Model::make(Subject::class), DatabaseTable::make('subjects')), 'declare only one of Model, DatabaseTable'],
    'duplicate definition' => [fn () => Source::define(Model::make(Subject::class), Pagination::make(5), Pagination::make(10)), 'Pagination is declared twice'],
    'not a model' => [fn () => compile(Source::define(Model::make(stdClass::class))), 'is not an Eloquent model class'],
    'missing model class' => [fn () => compile(Source::define(Model::make('App\Models\Missing'))), 'is not an Eloquent model class'],
    'bad page size' => [fn () => Pagination::make(0), 'page sizes must be positive'],
    'bad name' => [fn () => Name::make('Bad Name'), 'names are 1 to 64'],
    'columns on a model' => [fn () => compile(Source::define(Model::make(Subject::class), Columns::make(['id']))), 'Columns and Key are for table'],
    'cached without a name' => [fn () => compile(Source::define(Model::make(Subject::class), Cached::make())), 'cached sources need a unique Name'],
    'deep relation' => [fn () => Relations::make(['a.b.c.d']), 'traverses more than 3 relations'],
    'deep search path' => [fn () => Search::make(['a.b.c.d.name']), 'traverses more than 3 relations'],
    'relation sortable' => [fn () => Sortables::make(['topics.name']), 'no relation paths'],
    'duplicate filter key' => [fn () => Filterables::make([Filterable::make('age', [Integer::make()]), Filterable::make('age', [Integer::make()])]), 'declared twice'],
    'duplicate alias' => [fn () => Aggregates::make([RelationAggregate::count('topics'), RelationAggregate::count('topics')]), 'declared twice'],
]);

it('validates filter configurations', function (Closure $define, string $message): void {
    expect($define)->toThrow(InvalidSourceException::class, $message);
})->with([
    'empty configuration' => [fn () => Filterable::make('age', []), 'declare a list with a value type'],
    'no value type' => [fn () => Filterable::make('age', [Equal::make()]), 'declare exactly one value type'],
    'two value types' => [fn () => Filterable::make('age', [Integer::make(), Decimal::make()]), 'declare one value type once'],
    'duplicate operator' => [fn () => Filterable::make('age', [Integer::make(), Equal::make(), Equal::make()]), 'the "eq" operator once'],
    'range on text' => [fn () => Filterable::make('name', [StringType::make(), GreaterThan::make()]), 'the "gt" operator does not apply to string values'],
    'text on numbers' => [fn () => Filterable::make('age', [Integer::make(), Contains::make()]), 'the "contains" operator does not apply to integer values'],
    'equality on JSON' => [fn () => Filterable::make('tags', [Json::make(), Equal::make()]), 'the "eq" operator does not apply to json values'],
    'relation with a value type' => [fn () => Filterable::make('topics', [Integer::make(), Has::make()]), 'has and doesnt_have take no value type'],
    'relation mixed with values' => [fn () => Filterable::make('topics', [Has::make(), Equal::make()]), 'has and doesnt_have take no value type'],
    'enum with options' => [fn () => Filterable::make('level', [Enum::make(SubjectLevel::class), Options::make([1])]), 'remove Options'],
    'invalid option' => [fn () => Filterable::make('age', [Integer::make(), Options::make(['old'])]), 'every option must be a valid integer'],
    'unknown item' => [fn () => Filterable::make('age', [Integer::make(), 'gte']), 'is not a value type, operator'],
    'bad key' => [fn () => Filterable::make('Age; drop', [Integer::make()]), 'keys use lowercase letters'],
    'bad column' => [fn () => Filterable::make('age', [Integer::make(), Column::make('age; drop')]), 'not a valid column'],
    'not an enum' => [fn () => Enum::make(stdClass::class), 'is not a backed enum'], // @phpstan-ignore argument.type
]);

it('validates fixed filters', function (): void {
    expect(Filter::make('archived_at', operator: IsNull::make())->value)->toBeNull()
        ->and(Filter::make('level', [1, 2], In::make())->value)->toBe([1, 2])
        ->and(Filter::make('level', SubjectLevel::Advanced)->value)->toBe(3)
        ->and(Filter::make('at', new DateTimeImmutable('2026-01-02 03:04:05'))->value)->toBe('2026-01-02 03:04:05')
        ->and(fn () => Filter::make('name; drop table', 'x'))->toThrow(InvalidSourceException::class, 'not a valid column')
        ->and(fn () => Filter::make('level', [1], Between::make()))->toThrow(InvalidSourceException::class, 'exactly two')
        ->and(fn () => Filter::make('level', [], In::make()))->toThrow(InvalidSourceException::class, 'non-empty list')
        ->and(fn () => Filter::make('name', 5, Contains::make()))->toThrow(InvalidSourceException::class, 'does not apply to integer values')
        ->and(fn () => Filter::make('level', new stdClass))->toThrow(InvalidSourceException::class); // @phpstan-ignore argument.type
});

it('casts values of every built-in type', function (object $type, mixed $input, mixed $expected): void {
    expect($type->cast('field', $input))->toBe($expected); // @phpstan-ignore method.notFound
})->with([
    'time' => [Time::make(), '07:30', '07:30:00'],
    'uuid' => [Uuid::make(), 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'],
    'enum' => [Enum::make(SubjectLevel::class), '2', 2],
    'decimal' => [Decimal::make(), '12.50', 12.5],
    'json element' => [Json::make(), 'php', 'php'],
]);
