<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| GCEGO Data
|--------------------------------------------------------------------------
|
| Publish with "php artisan vendor:publish --tag=gcego-data-config".
|
*/

return [

    'pagination' => [
        // Page size of sources without their own Paginate size.
        'default_per_page' => 25,

        // Hard upper limit for every source and every request (?per_page=).
        'max_per_page' => 100,
    ],

    'search' => [
        // Longer search input is rejected with 400 Bad Request.
        'max_length' => 200,

        // Whitespace-separated terms; each must match one of the searchable fields.
        'max_terms' => 8,
    ],

    'filters' => [
        // Maximum number of values in one in / not_in filter.
        'max_values' => 100,
    ],

    'sorting' => [
        // Maximum number of requested sort fields (?sort=name,-created_at).
        'max_fields' => 3,

        // Append the source's unique key as the last ordering, so pages never overlap or skip rows.
        'stable' => true,
    ],

    'queries' => [
        // Reject unknown filter, sort and include keys (400) instead of ignoring them.
        'strict' => true,
    ],

    'authorization' => [
        // Gate ability checked for query-builder and collection sources. Eloquent sources use the
        // model's policies (viewAny, view, create, update, delete, restore, forceDelete).
        // Undefined abilities deny.
        'ability' => 'gcego-data.query',
    ],

    'cache' => [
        // Master switch for sources that declare new Cached(...). Off means every call reads fresh data.
        'enabled' => true,

        // Cache store for results (null: the default store). A store with atomic locks
        // (redis, memcached, database, file, array) also prevents stampedes.
        'store' => null,

        // Prefix of every key gcego/data writes.
        'prefix' => 'gcego-data',

        // Invalidate cached results when any Eloquent model of their source (or of an
        // eager-loaded relation) is saved, deleted or restored, also outside gcego/data.
        'invalidate_on_model_events' => true,

        // Seconds a cache miss holds its lock while it computes the result.
        'lock_seconds' => 10,
    ],

    'transactions' => [
        // Attempts for a write transaction that fails on a deadlock or serialization failure.
        'attempts' => 3,

        // Exponential backoff between attempts, with jitter (milliseconds).
        'backoff_ms' => 50,
        'max_backoff_ms' => 1000,
    ],

    'performance' => [
        // Statement timeout for reads, in milliseconds (MySQL/MariaDB and PostgreSQL). Null: none.
        'timeout_ms' => null,

        // Deepest row offset page-number pagination may reach (null: unlimited). Deep
        // offsets scan every skipped row; use filters or cursor pagination instead.
        'max_offset' => 100000,

        // Most items a collection source may load per call.
        'collection_max_items' => 10000,

        // Record executed statements (SQL and timing, never bindings) in every result.
        // Per call: QueryContext::profiled().
        'profile' => false,

        // Calls slower than this are logged (warning) and dispatch SlowQueryDetected. Null: off.
        'slow_query_ms' => 1000,

        // A statement repeated this often in one profiled call is reported as a likely N+1.
        'duplicate_query_threshold' => 5,

        // Mark returned models so a lazy-loaded relation is a violation (Laravel's
        // LazyLoadingViolationException, or your Model::handleLazyLoadingViolationUsing()).
        'prevent_lazy_loading' => false,
    ],

    'events' => [
        // Dispatch SourceQueried after every read (for auditing). Writes always dispatch events.
        'reads' => false,
    ],

];
