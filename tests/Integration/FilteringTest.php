<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Between;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\GreaterThan;
use Gcego\Data\Filters\Operators\GreaterThanOrEqual;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\IsNotNull;
use Gcego\Data\Filters\Operators\IsNull;
use Gcego\Data\Filters\Operators\LessThan;
use Gcego\Data\Filters\Operators\LessThanOrEqual;
use Gcego\Data\Filters\Operators\NotEqual;
use Gcego\Data\Filters\Operators\NotIn;
use Gcego\Data\Filters\Types\Boolean;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\Query;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => seedSubjects());

/**
 * The same definition over Eloquent, a query builder and a collection.
 *
 * @return array<string, Source>
 */
function filterSources(): array
{
    $nodes = [
        Filterables::make([
            Filterable::make('level', [Integer::make(), Equal::make(), NotEqual::make(), GreaterThan::make(), GreaterThanOrEqual::make(), LessThan::make(), LessThanOrEqual::make(), In::make(), NotIn::make(), Between::make(), IsNull::make(), IsNotNull::make()]),
            Filterable::make('code', [StringType::make(), Equal::make(), NotEqual::make(), In::make(), NotIn::make(), IsNull::make(), IsNotNull::make()]),
            Filterable::make('published', [Boolean::make(), Equal::make(), Column::make('is_published')]),
        ]),
        Sort::make('name'),
    ];
    $rows = Subject::query()->orderBy('id')->get()->map(fn (Subject $subject): array => $subject->only('id', 'name', 'code', 'level', 'is_published', 'institution_id'))->all();

    return [
        'eloquent' => Source::define(Model::make(Subject::class), ...$nodes),
        'query' => Source::define(DatabaseTable::make('subjects'), Constraints::make([Filter::make('deleted_at', operator: IsNull::make())]), ...$nodes),
        'collection' => Source::define(Items::make($rows), ...$nodes),
    ];
}

it('supports every operator with the same semantics on every provider', function (string $provider, array $filter, array $expected): void {
    expect(pluck(systemQuery(filterSources()[$provider], ['filter' => $filter]), 'name'))->toBe($expected);
})->with(['eloquent', 'query', 'collection'])->with([
    'eq' => [['level' => '2'], ['Biology', 'Chemistry']],
    'neq' => [['level' => ['neq' => '2']], ['Algebra', 'Drama', 'Economics']],
    'gt' => [['level' => ['gt' => '2']], ['Drama', 'Economics']],
    'gte' => [['level' => ['gte' => '2']], ['Biology', 'Chemistry', 'Drama', 'Economics']],
    'lt' => [['level' => ['lt' => '2']], ['Algebra']],
    'lte' => [['level' => ['lte' => '2']], ['Algebra', 'Biology', 'Chemistry']],
    'in list' => [['level' => ['1', '3']], ['Algebra', 'Drama', 'Economics']],
    'in comma' => [['level' => ['in' => '1,3']], ['Algebra', 'Drama', 'Economics']],
    'not_in' => [['level' => ['not_in' => ['1', '3']]], ['Biology', 'Chemistry']],
    'between' => [['level' => ['between' => ['2', '3']]], ['Biology', 'Chemistry', 'Drama', 'Economics']],
    'null' => [['code' => ['null' => '1']], ['Drama']],
    'not_null' => [['code' => ['not_null' => 'true']], ['Algebra', 'Biology', 'Chemistry', 'Economics']],
    'boolean' => [['published' => 'false'], ['Chemistry']],
    'combined (AND)' => [['level' => ['gte' => '2', 'lt' => '3'], 'published' => '1'], ['Biology']],
    'neq excludes nulls' => [['code' => ['neq' => 'MTH101']], ['Biology', 'Chemistry', 'Economics']],
]);

it('binds filter values instead of interpolating them', function (): void {
    $payload = "x' OR '1'='1'; DROP TABLE subjects; --";
    $source = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('code', [StringType::make(), Equal::make(), In::make()])]));

    $explained = app(DataManager::class)->explain($source, ['filter' => ['code' => $payload]], includeBindings: false);

    expect(systemQuery($source, ['filter' => ['code' => $payload]])->isEmpty())->toBeTrue()
        ->and($explained['sql'])->not->toContain('DROP')
        ->and($explained['sql'])->toContain('"subjects"."code" = ?')
        ->and($explained)->not->toHaveKey('values')
        ->and(Schema::hasTable('subjects'))->toBeTrue();
});

it('cannot widen mandatory constraints', function (): void {
    $source = Source::define(Model::make(Subject::class), Constraints::make([Filter::make('is_published', true), Query::make(fn ($query) => $query->where('institution_id', 1))]), Filterables::make([Filterable::make('published', [Boolean::make(), Equal::make(), In::make(), Column::make('is_published')]), Filterable::make('institution', [Integer::make(), Equal::make(), In::make(), NotIn::make(), Column::make('institution_id')])]));

    expect(pluck(systemQuery($source), 'name'))->toBe(['Algebra', 'Biology'])
        ->and(systemQuery($source, ['filter' => ['published' => 'false']])->isEmpty())->toBeTrue()
        ->and(systemQuery($source, ['filter' => ['institution' => ['in' => ['1', '2']]]])->pagination->total)->toBe(2)
        ->and(systemQuery($source, ['filter' => ['institution' => '2']])->isEmpty())->toBeTrue();
});
