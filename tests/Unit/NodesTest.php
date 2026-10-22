<?php

declare(strict_types=1);

use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Definitions\Includables;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Definitions\Transform;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Column;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Options;
use Gcego\Data\Filters\Types\Boolean;
use Gcego\Data\Filters\Types\Date;
use Gcego\Data\Filters\Types\DateTime;
use Gcego\Data\Filters\Types\Decimal;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Filters\Types\Time;
use Gcego\Data\Filters\Types\Uuid;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\StartsWithSearch;

it('declares filterable fields with trusted columns, types and options', function (): void {
    $filterable = Filterable::make('subject', [Integer::make(), Equal::make(), Column::make('subject_id'), Options::make([1, '2'])]);

    expect($filterable->column)->toBe('subject_id')
        ->and($filterable->options)->toBe([1, 2])
        ->and($filterable->operator('eq'))->toBeInstanceOf(Equal::class)
        ->and($filterable->operator('in'))->toBeNull()
        ->and(Filterable::make('status', [StringType::make(), Equal::make(), In::make()])->operatorNames())->toBe(['eq', 'in'])
        ->and($filterable->normalize())->toBe(['key' => 'subject', 'column' => 'subject_id', 'type' => 'integer', 'operators' => ['eq'], 'options' => [1, 2], 'label' => null]);
});

it('declares search fields and strategies', function (): void {
    $search = Search::make(['name', 'code', 'topics.name']);
    $custom = Search::make(['name'], StartsWithSearch::class);

    expect($search->fields)->toBe(['name', 'code', 'topics.name'])
        ->and($search->strategy)->toBeNull()
        ->and($custom->strategy)->toBe(StartsWithSearch::class)
        ->and(fn () => Search::make([]))->toThrow(InvalidSourceException::class, 'declare 1 to 20')
        ->and(fn () => Search::make(['name or 1=1']))->toThrow(InvalidSourceException::class)
        ->and(fn () => Search::make(['name'], stdClass::class))->toThrow(InvalidSourceException::class, 'does not implement'); // @phpstan-ignore argument.type
});

it('declares sorting', function (): void {
    expect(Sort::make('created_at', 'DESC')->orders[0]['direction'])->toBe(SortDirection::Descending)
        ->and(Sortables::make(['name', 'newest' => 'created_at'])->columns)->toBe(['name' => 'name', 'newest' => 'created_at'])
        ->and(fn () => Sort::make('name', 'sideways'))->toThrow(InvalidSourceException::class, 'direction must be')
        ->and(fn () => Sort::make(['name', 'code']))->toThrow(InvalidSourceException::class, 'field => direction pairs')
        ->and(fn () => Sortables::make(['topic' => 'topics.name']))->toThrow(InvalidSourceException::class, 'no relation paths')
        ->and(fn () => Sortables::make([]))->toThrow(InvalidSourceException::class, 'at least one column');
});

it('declares pagination', function (): void {
    expect(Pagination::cursor(20)->mode)->toBe(PaginationMode::Cursor)
        ->and(Pagination::simple()->perPage)->toBeNull()
        ->and(Pagination::make(25, max: 50)->max)->toBe(50)
        ->and(fn () => Pagination::make(0))->toThrow(InvalidSourceException::class)
        ->and(fn () => Pagination::make(50, max: 10))->toThrow(InvalidSourceException::class, 'max must not be smaller');
});

it('declares relationships', function (): void {
    $constraint = fn ($query) => $query;

    expect(array_keys(Relations::make(['topics', 'topics.lessons' => $constraint])->paths))->toBe(['topics', 'topics.lessons'])
        ->and(Relations::make(['topics' => $constraint])->paths['topics'])->toBe($constraint)
        ->and(Includables::make(['topics'])->normalize())->toBe(['relations' => ['topics']])
        ->and(fn () => Relations::make([]))->toThrow(InvalidSourceException::class, 'at least one')
        ->and(fn () => Relations::make(['topics' => 'not a closure']))->toThrow(InvalidSourceException::class); // @phpstan-ignore argument.type
});

it('transforms records without exposing hidden attributes', function (): void {
    $student = new Student(['name' => 'Ada', 'national_id' => 'SECRET-1', 'institution_id' => 1]);

    expect(Transform::only(['name', 'national_id'])->transform($student))->toBe(['name' => 'Ada'])
        ->and(Transform::only(['id'])->transform(['id' => 3, 'x' => 1]))->toBe(['id' => 3])
        ->and(Transform::only(['id'])->transform((object) ['id' => 4]))->toBe(['id' => 4])
        ->and(Transform::make(fn (array $row): int => $row['id'] * 2)->transform(['id' => 4]))->toBe(8)
        ->and($student->national_id)->toBe('SECRET-1')
        ->and(fn () => Transform::only([]))->toThrow(InvalidSourceException::class);
});

it('casts and validates filter values', function (FilterValueType $type, mixed $input, mixed $expected): void {
    expect($type->cast('field', $input))->toBe($expected);
})->with([
    [StringType::make(), 'abc', 'abc'],
    [StringType::make(), 12, '12'],
    [Integer::make(), '42', 42],
    [Integer::make(), '-7', -7],
    [Decimal::make(), '3.25', 3.25],
    [Boolean::make(), 'true', true],
    [Boolean::make(), '0', false],
    [Date::make(), '2026-02-28', '2026-02-28'],
    [DateTime::make(), '2026-02-28T10:15', '2026-02-28 10:15:00'],
    [Time::make(), '23:59:59', '23:59:59'],
]);

it('rejects invalid filter values', function (FilterValueType $type, mixed $input): void {
    expect(fn () => $type->cast('field', $input))->toThrow(InvalidFilterException::class);
})->with([
    [Integer::make(), '4.2'],
    [Integer::make(), '1 OR 1=1'],
    [Decimal::make(), '1e9'],
    [Boolean::make(), 'yes'],
    [Date::make(), '2026-02-30'],
    [DateTime::make(), '2026-02-28 25:00:00'],
    [Time::make(), '24:00'],
    [Uuid::make(), 'not-a-uuid'],
    [StringType::make(), ['a']],
    [StringType::make(), str_repeat('a', 256)],
    [StringType::make(), "line\nbreak"],
]);
