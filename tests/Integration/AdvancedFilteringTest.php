<?php

declare(strict_types=1);

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\DatabaseTable;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Items;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\CustomFilter;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Contains;
use Gcego\Data\Filters\Operators\DoesntHave;
use Gcego\Data\Filters\Operators\EndsWith;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\Has;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Operators\NotContains;
use Gcego\Data\Filters\Operators\StartsWith;
use Gcego\Data\Filters\Types\Boolean;
use Gcego\Data\Filters\Types\Enum;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\Json;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\MinLength;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\SubjectLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

function subjectsSource(SourceConfiguration ...$definitions): Source
{
    return Source::define(Model::make(Subject::class), Sort::make('name'), ...$definitions);
}

function textFilter(): Filterables
{
    return Filterables::make([Filterable::make('name', [StringType::make(), Contains::make(), NotContains::make(), StartsWith::make(), EndsWith::make()])]);
}

it('matches text case-insensitively with contains, starts_with, ends_with and not_contains', function (string $operator, string $value, array $expected): void {
    seedSubjects();

    expect(pluck(systemQuery(subjectsSource(textFilter()), ['filter' => ['name' => [$operator => $value]]]), 'name'))->toBe($expected);
})->with([
    'contains' => ['contains', 'MIS', ['Chemistry']],
    'starts_with' => ['starts_with', 'b', ['Biology']],
    'ends_with' => ['ends_with', 'RA', ['Algebra']],
    'not_contains' => ['not_contains', 'o', ['Algebra', 'Chemistry', 'Drama']],
]);

it('treats wildcards in text filter values literally', function (): void {
    seedSubjects();

    expect(systemQuery(subjectsSource(textFilter()), ['filter' => ['name' => ['contains' => '%']]])->count())->toBe(0)
        ->and(systemQuery(subjectsSource(textFilter()), ['filter' => ['name' => ['starts_with' => '_lgebra']]])->count())->toBe(0);
});

it('applies the same text operators to collection sources', function (): void {
    $source = Source::define(Items::make([['id' => 1, 'name' => 'Cardiology'], ['id' => 2, 'name' => 'Radiology'], ['id' => 3, 'name' => 'Oncology']]), textFilter());

    expect(pluck(systemQuery($source, ['filter' => ['name' => ['starts_with' => 'RAD']]]), 'name'))->toBe(['Radiology'])
        ->and(pluck(systemQuery($source, ['filter' => ['name' => ['not_contains' => 'olog']]]), 'name'))->toBe([]);
});

it('accepts only the values of an enum filter', function (): void {
    seedSubjects();
    $source = subjectsSource(Filterables::make([Filterable::make('level', [Enum::make(SubjectLevel::class), Equal::make(), In::make()])]));

    expect(pluck(systemQuery($source, ['filter' => ['level' => ['in' => ['2', '3']]]]), 'name'))->toBe(['Biology', 'Chemistry', 'Drama', 'Economics'])
        ->and(fn () => systemQuery($source, ['filter' => ['level' => '9']]))->toThrow(InvalidFilterException::class, 'not one of the allowed options');
});

it('filters by the existence of related records', function (): void {
    $subjects = seedSubjects();
    $subjects['drama']->topics()->each(fn ($topic) => $topic->lessons()->delete() && $topic->delete());
    $source = subjectsSource(Filterables::make([Filterable::make('topics', [Has::make(), DoesntHave::make()])]));

    expect(pluck(systemQuery($source, ['filter' => ['topics' => ['doesnt_have' => '1']]]), 'name'))->toBe(['Drama'])
        ->and(systemQuery($source, ['filter' => ['topics' => ['has' => '1']]])->count())->toBe(4)
        ->and(fn () => systemQuery($source, ['filter' => ['topics' => '1']]))->toThrow(InvalidFilterException::class, 'does not support the "eq" operator')
        ->and(pluck(systemQuery(subjectsSource(Constraints::make([Filter::make('topics', operator: DoesntHave::make())]))), 'name'))->toBe(['Drama']);
});

it('runs custom operators against databases and collections', function (): void {
    seedSubjects();
    $database = subjectsSource(Filterables::make([Filterable::make('name', [StringType::make(), Equal::make(), new MinLength])]));
    $collection = Source::define(Items::make([['id' => 1, 'name' => 'Ward'], ['id' => 2, 'name' => 'Theatre']]), Filterables::make([Filterable::make('name', [StringType::make(), new MinLength])]));

    expect(pluck(systemQuery($database, ['filter' => ['name' => ['min_length' => '9']]]), 'name'))->toBe(['Chemistry', 'Economics'])
        ->and(pluck(systemQuery($collection, ['filter' => ['name' => ['min_length' => '5']]]), 'name'))->toBe(['Theatre'])
        ->and(pluck(systemQuery(subjectsSource(Constraints::make([Filter::make('name', '9', new MinLength)]))), 'name'))->toBe(['Chemistry', 'Economics']);
});

it('runs custom filters with validated, cast values', function (): void {
    seedSubjects();
    $advanced = Filterables::make([CustomFilter::make('advanced', fn (Builder $query, bool $advanced) => $query->where('level', $advanced ? '>=' : '<', 3), [Boolean::make()])]);
    $collection = Source::define(Items::make([['id' => 1, 'beds' => 4], ['id' => 2, 'beds' => 40]]), Filterables::make([CustomFilter::make('large', fn (Collection $items, bool $large) => $items->filter(fn (array $ward): bool => ($ward['beds'] >= 20) === $large), [Boolean::make()])]));

    expect(pluck(systemQuery(subjectsSource($advanced), ['filter' => ['advanced' => 'true']]), 'name'))->toBe(['Drama', 'Economics'])
        ->and(pluck(systemQuery($collection, ['filter' => ['large' => '1']]), 'id'))->toBe([2])
        ->and(fn () => systemQuery(subjectsSource($advanced), ['filter' => ['advanced' => 'maybe']]))->toThrow(InvalidFilterException::class, 'must be a valid boolean')
        ->and(fn () => systemQuery(subjectsSource($advanced), ['filter' => ['advanced' => ['gt' => '1']]]))->toThrow(InvalidFilterException::class, 'does not support the "gt" operator');
});

it('matches elements of JSON array columns', function (): void {
    $source = Source::define(Items::make([['id' => 1, 'tags' => ['php', 'laravel']], ['id' => 2, 'tags' => ['go']]]), Filterables::make([Filterable::make('tags', [Json::make(), Contains::make()])]));

    expect(pluck(systemQuery($source, ['filter' => ['tags' => ['contains' => 'laravel']]]), 'id'))->toBe([1]);
});

it('rejects invalid custom filter declarations', function (Closure $declare, string $message): void {
    expect($declare)->toThrow(InvalidSourceException::class, $message);
})->with([
    'range operator' => [fn () => CustomFilter::make('age', fn ($query) => $query, [Integer::make(), Contains::make()]), 'does not apply to integer values'],
    'missing type' => [fn () => CustomFilter::make('age', fn ($query) => $query, [Equal::make()]), 'declare exactly one value type'],
]);

it('rejects relation features on non-Eloquent sources', function (): void {
    $relation = Filterables::make([Filterable::make('topics', [Has::make()])]);

    expect(fn () => systemQuery(Source::define(DatabaseTable::make('subjects'), $relation)))->toThrow(InvalidSourceException::class, 'relationships need an Eloquent model source')
        ->and(fn () => systemQuery(Source::define(Items::make([]), $relation)))->toThrow(InvalidSourceException::class, 'relationships need an Eloquent model source');
});

it('rejects a key declared by both a Filterable and a CustomFilter', function (): void {
    expect(fn () => Filterables::make([Filterable::make('level', [Integer::make()]), CustomFilter::make('level', fn ($query) => $query, [Integer::make()])]))->toThrow(InvalidSourceException::class, 'declared twice');
});
