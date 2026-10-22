<?php

declare(strict_types=1);

use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\FilterOperator;
use Gcego\Data\Filters\ValueType;
use Gcego\Data\Pagination\Paginate;
use Gcego\Data\Pagination\PaginationMode;
use Gcego\Data\Relationships\Includable;
use Gcego\Data\Relationships\With;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Sorting\Sortable;
use Gcego\Data\Sorting\SortDirection;
use Gcego\Data\Tests\Fixtures\Models\Student;
use Gcego\Data\Tests\Fixtures\StartsWithSearch;
use Gcego\Data\Transform\Transform;

it('declares filterable fields with trusted columns, types and options', function (): void {
    $filterable = new Filterable('subject', FilterOperator::Equals, ValueType::Integer, column: 'subject_id', options: [1, '2']);

    expect($filterable->column)->toBe('subject_id')
        ->and($filterable->options)->toBe([1, 2])
        ->and($filterable->allows(FilterOperator::Equals))->toBeTrue()
        ->and($filterable->allows(FilterOperator::In))->toBeFalse()
        ->and((new Filterable('status'))->operators)->toBe([FilterOperator::Equals, FilterOperator::In])
        ->and($filterable->normalize()['operators'])->toBe(['eq']);
});

it('rejects invalid filterable declarations', function (Closure $declare, string $message): void {
    expect($declare)->toThrow(InvalidSourceException::class, $message);
})->with([
    'key' => [fn () => new Filterable('Status!'), 'keys use lowercase'],
    'column' => [fn () => new Filterable('status', column: 'status) or (1=1'), 'not a valid column'],
    'operators' => [fn () => new Filterable('status', []), 'non-empty list'],
    'options' => [fn () => new Filterable('level', type: ValueType::Integer, options: ['one']), 'every option must be a valid integer'],
]);

it('declares search fields and strategies', function (): void {
    $search = new Search(['name', 'code'], 'topics.name');
    $custom = $search->using(StartsWithSearch::class);

    expect($search->fields)->toBe(['name', 'code', 'topics.name'])
        ->and($search->strategy())->toBeNull()
        ->and($custom->strategy())->toBe(StartsWithSearch::class)
        ->and($custom->fields)->toBe($search->fields)
        ->and(fn () => new Search)->toThrow(InvalidSourceException::class, 'at least one field')
        ->and(fn () => new Search('name or 1=1'))->toThrow(InvalidSourceException::class)
        ->and(fn () => $search->using(stdClass::class))->toThrow(InvalidSourceException::class, 'does not implement'); // @phpstan-ignore argument.type
});

it('declares sorting', function (): void {
    expect(Sort::desc('created_at')->direction)->toBe(SortDirection::Descending)
        ->and((new Sort('name', 'DESC'))->direction)->toBe(SortDirection::Descending)
        ->and((new Sortable('name', ['newest' => 'created_at']))->columns)->toBe(['name' => 'name', 'newest' => 'created_at'])
        ->and(Sortable::map(['newest' => 'created_at'])->columns)->toBe(['newest' => 'created_at'])
        ->and(fn () => new Sort('name', 'sideways'))->toThrow(InvalidSourceException::class, 'direction must be')
        ->and(fn () => new Sortable(['topic' => 'topics.name']))->toThrow(InvalidSourceException::class, 'no relation paths')
        ->and(fn () => new Sortable)->toThrow(InvalidSourceException::class, 'at least one key');
});

it('declares pagination', function (): void {
    expect(Paginate::cursor(20)->mode)->toBe(PaginationMode::Cursor)
        ->and(Paginate::simple()->perPage)->toBeNull()
        ->and((new Paginate(25, max: 50))->max)->toBe(50)
        ->and(fn () => new Paginate(0))->toThrow(InvalidSourceException::class)
        ->and(fn () => new Paginate(50, max: 10))->toThrow(InvalidSourceException::class, 'max must not be smaller');
});

it('declares relationships', function (): void {
    $constraint = fn ($query) => $query;

    expect(array_keys((new With('topics', ['topics.lessons' => $constraint]))->relations))->toBe(['topics', 'topics.lessons'])
        ->and((new With(['topics' => $constraint]))->relations['topics'])->toBe($constraint)
        ->and((new Includable('topics'))->normalize())->toBe(['relations' => ['topics']])
        ->and(fn () => new With)->toThrow(InvalidSourceException::class, 'at least one')
        ->and(fn () => new With(['topics' => 'not a closure']))->toThrow(InvalidSourceException::class); // @phpstan-ignore argument.type
});

it('transforms records without exposing hidden attributes', function (): void {
    $student = new Student(['name' => 'Ada', 'national_id' => 'SECRET-1', 'institution_id' => 1]);

    expect(Transform::only('name', 'national_id')->transform($student))->toBe(['name' => 'Ada'])
        ->and(Transform::only('id')->transform(['id' => 3, 'x' => 1]))->toBe(['id' => 3])
        ->and(Transform::only('id')->transform((object) ['id' => 4]))->toBe(['id' => 4])
        ->and((new Transform(fn (array $row): int => $row['id'] * 2))->transform(['id' => 4]))->toBe(8)
        ->and($student->national_id)->toBe('SECRET-1')
        ->and(fn () => Transform::only())->toThrow(InvalidSourceException::class);
});

it('casts and validates filter values', function (ValueType $type, mixed $input, mixed $expected): void {
    expect($type->cast('field', $input))->toBe($expected);
})->with([
    [ValueType::String, 'abc', 'abc'],
    [ValueType::String, 12, '12'],
    [ValueType::Integer, '42', 42],
    [ValueType::Integer, '-7', -7],
    [ValueType::Decimal, '3.25', 3.25],
    [ValueType::Boolean, 'true', true],
    [ValueType::Boolean, '0', false],
    [ValueType::Date, '2026-02-28', '2026-02-28'],
    [ValueType::DateTime, '2026-02-28T10:15', '2026-02-28 10:15:00'],
]);

it('rejects invalid filter values', function (ValueType $type, mixed $input): void {
    expect(fn () => $type->cast('field', $input))->toThrow(InvalidFilterException::class);
})->with([
    [ValueType::Integer, '4.2'],
    [ValueType::Integer, '1 OR 1=1'],
    [ValueType::Decimal, '1e9'],
    [ValueType::Boolean, 'yes'],
    [ValueType::Date, '2026-02-30'],
    [ValueType::DateTime, '2026-02-28 25:00:00'],
    [ValueType::String, ['a']],
    [ValueType::String, str_repeat('a', 256)],
    [ValueType::String, "line\nbreak"],
]);
