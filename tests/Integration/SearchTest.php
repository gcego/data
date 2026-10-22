<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Property;
use Gcego\Data\Search\Search;
use Gcego\Data\Sorting\Sort;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\StartsWithSearch;

beforeEach(fn () => seedSubjects());

it('searches one or several configured fields', function (array $fields, string $term, array $expected): void {
    expect(pluck(systemQuery(new Source(Subject::class, new Search($fields), new Sort('name')), ['search' => $term]), 'name'))->toBe($expected);
})->with([
    'single field' => [['name'], 'bio', ['Biology']],
    'case-insensitive' => [['name'], 'ALGEBRA', ['Algebra']],
    'several fields' => [['name', 'code'], '101', ['Algebra', 'Biology']],
    'every term must match' => [['name', 'code'], 'chm chem', ['Chemistry']],
    'terms across fields' => [['name', 'code'], 'eco 301', ['Economics']],
    'no match' => [['name'], 'zoology', []],
]);

it('ignores empty search terms', function (): void {
    expect(systemQuery(new Source(Subject::class, new Search('name')), ['search' => '   '])->pagination->total)->toBe(5);
});

it('matches wildcard characters literally', function (): void {
    Subject::query()->create(['name' => '100% Maths', 'institution_id' => 1]);
    Subject::query()->create(['name' => 'Snake_case', 'institution_id' => 1]);
    $source = new Source(Subject::class, new Search('name'));

    expect(pluck(systemQuery($source, ['search' => '%']), 'name'))->toBe(['100% Maths'])
        ->and(pluck(systemQuery($source, ['search' => '_']), 'name'))->toBe(['Snake_case'])
        ->and(systemQuery($source, ['search' => '!'])->isEmpty())->toBeTrue();
});

it('rejects overly long search input', function (): void {
    systemQuery(new Source(Subject::class, new Search('name')), ['search' => str_repeat('a', 201)]);
})->throws(InvalidQueryException::class, 'longer than 200 characters');

it('uses a custom strategy when configured', function (): void {
    $source = new Source(Subject::class, (new Search('name'))->using(StartsWithSearch::class));

    expect(pluck(systemQuery($source, ['search' => 'Bio']), 'name'))->toBe(['Biology'])
        ->and(systemQuery($source, ['search' => 'ology'])->isEmpty())->toBeTrue();
});

it('compiles the escape clause for the active driver', function (): void {
    $sql = app(DataManager::class)->explain(new Source(Subject::class, new Search('name')), ['search' => 'x'])['sql'];

    expect($sql)->toContain("\"subjects\".\"name\" like ? escape '!'");
});

it('generates driver-appropriate search SQL for MySQL and PostgreSQL', function (string $driver, string $expected): void {
    config()->set('database.connections.compat', ['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'unused', 'username' => 'unused', 'password' => '']);

    // Laravel connects lazily: compiling SQL needs the grammar, not a server.
    $sql = app(DataManager::class)->explain(new Source(Subject::class, new Property(Source::Connection, 'compat'), new Search('name', 'topics.name')), ['search' => 'x'])['sql'];

    expect($sql)->toContain($expected);
})->with([
    'mysql' => ['mysql', "`subjects`.`name` like ? escape '!'"],
    'pgsql' => ['pgsql', "\"subjects\".\"name\" ilike ? escape '!'"],
]);
