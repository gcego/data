<?php

declare(strict_types=1);

use Gcego\Data\Definitions\Transform;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Resources\SubjectResource;

it('removes listed attributes with except', function (): void {
    expect(Transform::except(['code', 'level'])->transform(['name' => 'Algebra', 'code' => 'MTH101', 'level' => 1]))->toBe(['name' => 'Algebra']);
});

it('maps records to a fixed shape, in order, with nulls for missing values', function (): void {
    $subject = new Subject(['name' => 'Algebra', 'code' => 'MTH101']);

    $mapped = Transform::map(['title' => 'name', 'code', 'level', 'upper' => fn (Subject $record): string => strtoupper($record->name)])->transform($subject);

    expect($mapped)->toBe(['title' => 'Algebra', 'code' => 'MTH101', 'level' => null, 'upper' => 'ALGEBRA']);
});

it('shapes records with a Laravel API resource', function (): void {
    expect(Transform::resource(SubjectResource::class)->transform(new Subject(['name' => 'Algebra', 'code' => 'MTH101'])))->toBe(['label' => 'Algebra (MTH101)']);
});

it('rejects invalid shapes', function (Closure $declare, string $message): void {
    expect($declare)->toThrow(InvalidSourceException::class, $message);
})->with([
    'empty except' => [fn () => Transform::except([]), 'list at least one attribute'],
    'empty map' => [fn () => Transform::map([]), 'list at least one field'],
    'not a resource' => [fn () => Transform::resource(Subject::class), 'is not a'], // @phpstan-ignore argument.type
]);
