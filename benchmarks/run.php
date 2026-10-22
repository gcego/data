<?php

declare(strict_types=1);

/**
 * gcego/data benchmark: php benchmarks/run.php [rows]
 *
 * Boots Laravel through Orchestra Testbench with in-memory SQLite, seeds
 * [rows] subjects (default 10000) with topics, and measures real executions
 * through DataManager. Numbers depend on the machine; compare runs on the same one.
 */

use Gcego\Core\GcegoServiceProvider;
use Gcego\Data\DataManager;
use Gcego\Data\DataServiceProvider;
use Gcego\Data\Definitions\Filterables;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Pagination;
use Gcego\Data\Definitions\Relations;
use Gcego\Data\Definitions\Search;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Definitions\Sortables;
use Gcego\Data\Filters\Filterable;
use Gcego\Data\Filters\Operators\Equal;
use Gcego\Data\Filters\Operators\GreaterThanOrEqual;
use Gcego\Data\Filters\Operators\In;
use Gcego\Data\Filters\Types\Integer;
use Gcego\Data\Filters\Types\StringType;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application as Testbench;

require dirname(__DIR__).'/vendor/autoload.php';

$rows = max(100, (int) ($argv[1] ?? 10000));

$app = Testbench::create();
$app['config']->set('database.default', 'bench');
$app['config']->set('database.connections.bench', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app->register(GcegoServiceProvider::class);
$app->register(DataServiceProvider::class);

Schema::create('subjects', static function (Table $table): void {
    $table->id();
    $table->string('name')->index();
    $table->string('code')->nullable();
    $table->boolean('is_published')->default(false);
    $table->integer('level')->default(1)->index();
    $table->unsignedInteger('institution_id')->default(1);
    $table->timestamps();
    $table->softDeletes();
});
Schema::create('topics', static function (Table $table): void {
    $table->id();
    $table->foreignId('subject_id')->index();
    $table->string('name');
    $table->timestamps();
});

$now = date('Y-m-d H:i:s');

foreach (array_chunk(range(1, $rows), 500) as $chunk) {
    DB::table('subjects')->insert(array_map(static fn (int $i): array => ['name' => sprintf('Subject %06d', $i), 'code' => 'C'.$i, 'is_published' => $i % 3 !== 0, 'level' => $i % 5, 'institution_id' => $i % 4 + 1, 'created_at' => $now, 'updated_at' => $now], $chunk));
    DB::table('topics')->insert(array_map(static fn (int $i): array => ['subject_id' => $i, 'name' => 'Topic '.$i, 'created_at' => $now, 'updated_at' => $now], $chunk));
}

$queries = 0;
Event::listen(QueryExecuted::class, static function () use (&$queries): void {
    $queries++;
});

$data = $app->make(DataManager::class);
$system = QueryContext::system();
$listing = Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('level', [Integer::make(), Equal::make(), GreaterThanOrEqual::make()])]), Search::make(['name', 'code']), Sort::make('name', 'asc'), Sortables::make(['name', 'level']), Pagination::make(25));
$withTopics = Source::define(Model::make(Subject::class), Relations::make(['topics']), Pagination::make(25));

/**
 * @return array{ms: float, per: float, queries: float}
 */
$measure = static function (int $count, Closure $callback) use (&$queries): array {
    $callback(0);
    $before = $queries;
    $start = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $callback($i);
    }

    $ms = (hrtime(true) - $start) / 1e6;

    return ['ms' => $ms, 'per' => $ms / $count, 'queries' => ($queries - $before) / $count];
};

$results = [
    'define + compile a source' => $measure(1000, static fn () => $data->definition(Source::define(Model::make(Subject::class), Filterables::make([Filterable::make('level', [StringType::make(), Equal::make(), In::make()])]), Search::make(['name']), Pagination::make(25)))),
    'page 1 (pages mode, count + select)' => $measure(100, static fn () => $data->query($listing, [], $system)),
    'page 1 (simple, no count)' => $measure(100, static fn () => $data->query(Source::define(Model::make(Subject::class), Pagination::simple(25)), [], $system)),
    'page 1 (cursor)' => $measure(100, static fn () => $data->query(Source::define(Model::make(Subject::class), Pagination::cursor(25)), [], $system)),
    'deep page (offset ~90%)' => $measure(50, static fn () => $data->query($listing, ['page' => intdiv($rows, 25) - 10], $system)),
    'filter + search + sort' => $measure(100, static fn (int $i) => $data->query($listing, ['filter' => ['level' => ['gte' => (string) ($i % 5)]], 'search' => 'Subject 00', 'sort' => '-level,name'], $system)),
    'eager load 25 x topics' => $measure(100, static fn () => $data->query($withTopics, [], $system)),
    'record by key (scoped)' => $measure(500, static fn (int $i) => $data->find($listing, ($i % $rows) + 1, $system)),
];

printf("gcego/data benchmark, PHP %s, SQLite in memory, %d subjects\n\n", PHP_VERSION, $rows);
printf("%-38s %10s %12s %9s\n", 'case', 'total ms', 'per call ms', 'queries');

foreach ($results as $case => ['ms' => $ms, 'per' => $per, 'queries' => $count]) {
    printf("%-38s %10.1f %12.3f %9.1f\n", $case, $ms, $per, $count);
}

printf("\npeak memory: %.1f MB\n", memory_get_peak_usage(true) / 1048576);
