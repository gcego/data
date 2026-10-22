<?php

declare(strict_types=1);

use Gcego\Data\Definition\SourceCompiler;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Property;
use Gcego\Data\Query\Query;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Source;
use Gcego\Data\Support\Callback;
use Gcego\Data\Tests\Fixtures\Models\Subject;

function compile(Source $source): SourceDefinition
{
    return (new SourceCompiler)->compile($source);
}

it('accepts the model shorthand', function (): void {
    $definition = compile(new Source(Subject::class));

    expect($definition->model)->toBe(Subject::class)
        ->and($definition->provider)->toBe('eloquent')
        ->and($definition->name)->toBe('subjects')
        ->and($definition->pagination)->toEqual(new Paginate);
});

it('accepts the advanced Property syntax', function (): void {
    $definition = compile(new Source(new Property(Source::Model, Subject::class), new Property(Source::Pagination, 25)));

    expect($definition->model)->toBe(Subject::class)
        ->and($definition->pagination->perPage)->toBe(25);
});

it('accepts declarative query configuration', function (): void {
    $definition = compile(new Source(Subject::class, new Filter('is_published', true), new Sort('name', 'asc'), new Paginate(25)));

    expect($definition->filters)->toHaveCount(1)
        ->and($definition->filters[0]->normalize())->toBe(['field' => 'is_published', 'operator' => 'eq', 'value' => true])
        ->and($definition->sorts[0]->direction)->toBe(SortDirection::Ascending)
        ->and($definition->pagination->perPage)->toBe(25);
});

it('treats property forms and node forms as equivalent', function (): void {
    $properties = compile(new Source(
        Subject::class,
        new Property(Source::Filters, ['is_published' => true, 'level' => [1, 2]]),
        new Property(Source::Search, ['name', 'code']),
        new Property(Source::Sort, ['-level', 'name']),
        new Property(Source::Pagination, 10),
        new Property(Source::Relationships, ['topics']),
        new Property(Source::Query, new Callback(fn ($query) => $query)),
    ));

    expect(array_map(fn (Filter $filter): array => $filter->normalize(), $properties->filters))->toBe([
        ['field' => 'is_published', 'operator' => 'eq', 'value' => true],
        ['field' => 'level', 'operator' => 'in', 'value' => [1, 2]],
    ])
        ->and($properties->search?->fields)->toBe(['name', 'code'])
        ->and(array_map(fn (Sort $sort): array => $sort->normalize(), $properties->sorts))->toBe([['field' => 'level', 'direction' => 'desc'], ['field' => 'name', 'direction' => 'asc']])
        ->and($properties->pagination->perPage)->toBe(10)
        ->and(array_keys($properties->with))->toBe(['topics'])
        ->and($properties->constraints[0])->toBeInstanceOf(Query::class);
});

it('rejects invalid and missing models', function (Source $source, string $message): void {
    expect(fn () => compile($source))->toThrow(InvalidSourceException::class, $message);
})->with([
    'not a model' => [new Source(stdClass::class), 'Invalid property "model"'],
    'missing class' => [new Source('App\Models\Missing'), 'Invalid property "model"'],
    'no origin' => [new Source(new Property(Source::Pagination, 5)), 'declare a model class'],
    'two origins' => [new Source(Subject::class, new Property(Source::Table, 'subjects')), 'declare only one of model, table, builder or items'],
    'bad pagination' => [new Source(Subject::class, new Property(Source::Pagination, 0)), 'Invalid property "pagination"'],
    'duplicate pagination' => [new Source(Subject::class, new Property(Source::Pagination, 5), new Paginate(10)), 'declare pagination once'],
    'duplicate search' => [new Source(Subject::class, new Search('name'), new Search('code')), 'declare searchable fields once'],
    'sort twice' => [new Source(Subject::class, new Property(Source::Sort, ['name']), new Sort('code')), 'either with the sort property or with Sort nodes'],
]);

it('is immutable and never queries the database while being defined', function (): void {
    $queries = queriesDuring(function () use (&$source, &$copy): void {
        $source = new Source(Subject::class, new Filter('is_published', true));
        $copy = $source->withConfigurations(new Paginate(5));
        compile($source);
        compile($copy);
    });

    expect($queries)->toBe([])
        ->and($source->hasConfiguration(Paginate::class))->toBeFalse()
        ->and($copy->hasConfiguration(Paginate::class))->toBeTrue()
        ->and($source->hasConfiguration(Filter::class))->toBeTrue()
        ->and($source->named('catalogue')->name())->toBe('catalogue')
        ->and($source->name())->toBeNull()
        ->and(fn () => $source->configuration(Filter::class)->field = 'x')->toThrow(Error::class); // @phpstan-ignore property.readOnlyAssignOutOfClass, property.nonObject
});

it('caches the compiled definition per source instance', function (): void {
    $compiler = new SourceCompiler;
    $source = new Source(Subject::class);

    expect($compiler->compile($source))->toBe($compiler->compile($source))
        ->and($compiler->compile(new Source(Subject::class)))->not->toBe($compiler->compile($source));
});

it('normalizes without exposing callbacks', function (): void {
    $source = new Source(Subject::class, new Query(fn ($query) => $query->where('secret', 1)), new With(['topics' => fn ($query) => $query]));
    $json = (string) json_encode($source->normalize());

    expect($json)->toContain('"constraint":"closure"')
        ->and($json)->toContain('"relations":["topics"]')
        ->and($json)->not->toContain('secret')
        ->and((string) json_encode(Source::builder(fn () => 'secret')->normalize()))->toContain('"builder":{"runtime"')->not->toContain('secret');
});

it('rejects malformed declarations when constructed', function (Closure $declare, string $message): void {
    expect($declare)->toThrow(InvalidSourceException::class, $message);
})->with([
    'duplicate property' => [fn () => new Source(Subject::class, new Property(Source::Model, Subject::class)), 'declared twice'],
    'misplaced model' => [fn () => new Source(new Property(Source::Pagination, 5), Subject::class), 'only the first argument'],
    'closure value' => [fn () => new Property(Source::Query, fn () => null), 'must be null, a scalar'],
    'invalid key' => [fn () => new Property('bad key', 1), 'not a valid property key'],
]);

it('reports unknown properties and invalid names when compiled', function (): void {
    expect(fn () => compile(new Source(Subject::class, new Property('colour', 'red'))))->toThrow(InvalidSourceException::class, 'Unknown property "colour"')
        ->and(fn () => compile(new Source(Subject::class, new Property(Source::Name, 'Bad Name'))))->toThrow(InvalidSourceException::class, 'Invalid property "name"');
});

it('offers named constructors for every origin', function (): void {
    expect(compile(Source::table('report_rows'))->provider)->toBe('query')
        ->and(compile(Source::table('report_rows'))->table)->toBe('report_rows')
        ->and(compile(Source::builder(fn () => null))->provider)->toBe('query')
        ->and(compile(Source::collection([['id' => 1]]))->provider)->toBe('collection')
        ->and(compile(Source::model(Subject::class, Paginate::simple(5)))->pagination->mode)->toBe(PaginationMode::Simple)
        ->and(compile(new Source(Subject::class, new Property(Source::Name, 'catalogue')))->name)->toBe('catalogue');
});

it('validates fixed filters', function (): void {
    expect((new Filter('archived_at', operator: FilterOperator::Null))->value)->toBeNull()
        ->and((new Filter('level', [1, 2], 'in'))->value)->toBe([1, 2])
        ->and((new Filter('status', PaginationMode::Pages))->value)->toBe('pages')
        ->and((new Filter('at', new DateTimeImmutable('2026-01-02 03:04:05')))->value)->toBe('2026-01-02 03:04:05')
        ->and(fn () => new Filter('name; drop table', 'x'))->toThrow(InvalidSourceException::class, 'not a valid column')
        ->and(fn () => new Filter('level', [1], FilterOperator::Between))->toThrow(InvalidSourceException::class, 'exactly two')
        ->and(fn () => new Filter('level', [], FilterOperator::In))->toThrow(InvalidSourceException::class, 'non-empty list')
        ->and(fn () => new Filter('level', 1, 'like'))->toThrow(InvalidSourceException::class, 'unknown operator')
        ->and(fn () => new Filter('level', new stdClass))->toThrow(InvalidSourceException::class); // @phpstan-ignore argument.type
});
