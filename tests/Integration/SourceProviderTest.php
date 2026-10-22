<?php

declare(strict_types=1);

use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Facades\Data;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Tests\Fixtures\Sources\NotASource;
use Gcego\Data\Tests\Fixtures\Sources\PublishedSubjects;
use Gcego\Data\Tests\Fixtures\Tenancy;

it('queries a source provider resolved through the container', function (): void {
    seedSubjects();
    app(Tenancy::class)->institution = 2;

    $result = Data::query(PublishedSubjects::class, context: QueryContext::system());

    expect($result->source)->toBe('published-subjects')
        ->and(pluck($result, 'name'))->toBe(['Drama', 'Economics']);
});

it('accepts provider instances for every operation', function (): void {
    $subjects = seedSubjects();
    $provider = app(PublishedSubjects::class);

    expect(Data::definition($provider)->name)->toBe('published-subjects')
        ->and(Data::findOrFail($provider, $subjects['algebra']->id, QueryContext::system())->name)->toBe('Algebra')
        ->and(Data::inspect($provider)->isHealthy())->toBeTrue();
});

it('rejects class names that are not source providers without instantiating them', function (): void {
    expect(fn () => Data::query(NotASource::class, context: QueryContext::system()))->toThrow(InvalidSourceException::class, 'does not implement')
        ->and(fn () => Data::query('Gcego\Data\Tests\Missing', context: QueryContext::system()))->toThrow(InvalidSourceException::class, 'does not implement');
});

it('never queries while resolving and compiling a provider', function (): void {
    expect(queriesDuring(fn () => Data::definition(PublishedSubjects::class)))->toBe([]);
});
