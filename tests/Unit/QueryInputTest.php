<?php

declare(strict_types=1);

use Gcego\Data\Exceptions\InvalidQueryException;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Sorting\SortDirection;
use Illuminate\Http\Request;

it('parses query-string parameters', function (): void {
    $input = QueryInput::fromArray([
        'filter' => ['status' => 'published', 'score' => ['gte' => '50', 'lte' => '90'], 'level' => ['1', '2']],
        'search' => '  algebra  ',
        'sort' => '-created_at,name',
        'page' => '2',
        'per_page' => 50,
        'cursor' => 'eyJpZCI6MX0',
        'include' => 'topics, topics.lessons',
    ]);

    expect($input->filters)->toBe(['status' => ['eq' => 'published'], 'score' => ['gte' => '50', 'lte' => '90'], 'level' => ['in' => ['1', '2']]])
        ->and($input->search)->toBe('algebra')
        ->and($input->sorts)->toBe([['key' => 'created_at', 'direction' => SortDirection::Descending], ['key' => 'name', 'direction' => SortDirection::Ascending]])
        ->and($input->page)->toBe(2)
        ->and($input->perPage)->toBe(50)
        ->and($input->includes)->toBe(['topics', 'topics.lessons'])
        ->and(QueryInput::fromArray($input->toArray()))->toEqual($input);
});

it('reads requests, optionally from a parameter group', function (): void {
    $request = Request::create('/subjects?search=bio&subjects[sort]=-name&subjects[page]=3');

    expect(QueryInput::fromRequest($request)->search)->toBe('bio')
        ->and(QueryInput::fromRequest($request, 'subjects')->page)->toBe(3)
        ->and(QueryInput::fromRequest($request, 'subjects')->search)->toBeNull()
        ->and(QueryInput::none()->isEmpty())->toBeTrue()
        ->and(QueryInput::fromArray(['search' => '   '])->search)->toBeNull();
});

it('rejects malformed parameters', function (array $input, string $message): void {
    expect(fn () => QueryInput::fromArray($input))->toThrow(InvalidQueryException::class, $message);
})->with([
    'filter not a group' => [['filter' => 'status'], '"filter" must be a group'],
    'filter list keys' => [['filter' => ['a', 'b']], 'keys must be field names'],
    'nested objects' => [['filter' => ['status' => ['eq' => ['x' => ['y']]]]], 'must be a list of values'],
    'search array' => [['search' => ['a']], '"search" must be a string'],
    'page zero' => [['page' => '0'], '"page" must be a positive integer'],
    'page negative' => [['page' => -1], '"page" must be a positive integer'],
    'page text' => [['page' => '2; drop'], '"page" must be a positive integer'],
    'per page huge' => [['per_page' => '99999999999'], '"per_page" must be a positive integer'],
    'cursor characters' => [['cursor' => "abc'--"], 'not a valid cursor'],
    'cursor too long' => [['cursor' => str_repeat('a', 2049)], 'not a valid cursor'],
    'sort object' => [['sort' => ['a' => 'b']], 'comma-separated string or a list'],
    'too many includes' => [['include' => implode(',', range(1, 51))], 'too many items'],
]);

it('reports HTTP 400 for rejected input without echoing values', function (): void {
    try {
        QueryInput::fromArray(['page' => '<script>alert(1)</script>']);
    } catch (InvalidQueryException $exception) {
        expect($exception->getStatusCode())->toBe(400)
            ->and($exception->getMessage())->not->toContain('<script>');
    }
});
