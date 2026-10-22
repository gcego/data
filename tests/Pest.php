<?php

declare(strict_types=1);

use Gcego\Data\DataManager;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Results\DataResult;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Lesson;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\Topic;
use Gcego\Data\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

pest()->extend(TestCase::class)->in('Unit', 'Integration', 'Feature');

/**
 * Six subjects across two institutions, one soft-deleted, with topics and lessons.
 *
 * @return array<string, Subject>
 */
function seedSubjects(): array
{
    $rows = [
        'algebra' => ['name' => 'Algebra', 'code' => 'MTH101', 'is_published' => true, 'level' => 1, 'institution_id' => 1],
        'biology' => ['name' => 'Biology', 'code' => 'BIO101', 'is_published' => true, 'level' => 2, 'institution_id' => 1],
        'chemistry' => ['name' => 'Chemistry', 'code' => 'CHM201', 'is_published' => false, 'level' => 2, 'institution_id' => 1],
        'drama' => ['name' => 'Drama', 'code' => null, 'is_published' => true, 'level' => 3, 'institution_id' => 2],
        'economics' => ['name' => 'Economics', 'code' => 'ECO301', 'is_published' => true, 'level' => 3, 'institution_id' => 2],
        'french' => ['name' => 'French', 'code' => 'FRE101', 'is_published' => true, 'level' => 1, 'institution_id' => 1],
    ];
    $subjects = [];

    foreach ($rows as $key => $row) {
        $subjects[$key] = Subject::query()->create($row);
    }

    foreach ($subjects as $subject) {
        foreach (['Introduction', 'Practice'] as $name) {
            $topic = Topic::query()->create(['subject_id' => $subject->id, 'name' => $subject->name.' '.$name]);
            Lesson::query()->create(['topic_id' => $topic->id, 'title' => $topic->name.' lesson']);
        }
    }

    $subjects['french']->delete();

    return $subjects;
}

/**
 * Query as trusted system code (no authorization), the common setup in provider tests.
 *
 * @param  array<mixed>  $input
 */
function systemQuery(Source $source, array $input = []): DataResult
{
    return app(DataManager::class)->query($source, $input, QueryContext::system());
}

/**
 * @param  iterable<mixed>  $items
 * @return list<mixed>
 */
function pluck(iterable $items, string $field): array
{
    $values = [];

    foreach ($items as $item) {
        $values[] = data_get($item, $field);
    }

    return $values;
}

/**
 * Run a callback and return the SQL statements it executed ("connection: sql" for non-default connections).
 *
 * @return list<string>
 */
function queriesDuring(Closure $callback): array
{
    $queries = [];
    $recording = true;

    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries, &$recording): void {
        if ($recording) {
            $queries[] = ($query->connectionName === 'testing' ? '' : $query->connectionName.': ').$query->sql;
        }
    });

    try {
        $callback();
    } finally {
        $recording = false;
    }

    return $queries;
}
