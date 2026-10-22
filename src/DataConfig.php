<?php

declare(strict_types=1);

namespace Gcego\Data;

use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * Validated gcego-data configuration.
 */
final readonly class DataConfig
{
    public function __construct(
        public int $defaultPerPage = 25,
        public int $maxPerPage = 100,
        public int $searchMaxLength = 200,
        public int $searchMaxTerms = 8,
        public int $filterMaxValues = 100,
        public int $sortMaxFields = 3,
        public bool $stableOrdering = true,
        public bool $strict = true,
        public string $ability = 'gcego-data.query',
        public bool $cacheEnabled = true,
        public ?string $cacheStore = null,
        public string $cachePrefix = 'gcego-data',
        public bool $cacheInvalidateOnModelEvents = true,
        public int $cacheLockSeconds = 10,
        public int $transactionAttempts = 3,
        public int $transactionBackoffMs = 50,
        public int $transactionMaxBackoffMs = 1000,
        public ?int $timeoutMs = null,
        public ?int $maxOffset = 100000,
        public int $collectionMaxItems = 10000,
        public bool $profile = false,
        public ?int $slowQueryMs = 1000,
        public int $duplicateQueryThreshold = 5,
        public bool $preventLazyLoading = false,
        public bool $readEvents = false,
    ) {
        $positive = [
            'pagination.default_per_page' => $defaultPerPage,
            'pagination.max_per_page' => $maxPerPage,
            'search.max_length' => $searchMaxLength,
            'search.max_terms' => $searchMaxTerms,
            'filters.max_values' => $filterMaxValues,
            'sorting.max_fields' => $sortMaxFields,
            'cache.lock_seconds' => $cacheLockSeconds,
            'transactions.attempts' => $transactionAttempts,
            'performance.collection_max_items' => $collectionMaxItems,
            'performance.duplicate_query_threshold' => $duplicateQueryThreshold,
            'performance.timeout_ms' => $timeoutMs ?? 1,
            'performance.max_offset' => $maxOffset ?? 1,
            'performance.slow_query_ms' => $slowQueryMs ?? 1,
        ];

        foreach ($positive as $name => $value) {
            if ($value < 1) {
                throw InvalidSourceException::invalidDefinition('gcego-data configuration', sprintf('%s must be a positive integer.', $name));
            }
        }

        if ($transactionBackoffMs < 0 || $transactionMaxBackoffMs < $transactionBackoffMs) {
            throw InvalidSourceException::invalidDefinition('gcego-data configuration', 'transactions.backoff_ms must be at least 0 and at most transactions.max_backoff_ms.');
        }

        if ($defaultPerPage > $maxPerPage) {
            throw InvalidSourceException::invalidDefinition('gcego-data configuration', 'pagination.default_per_page must not exceed pagination.max_per_page.');
        }

        if (trim($ability) === '') {
            throw InvalidSourceException::invalidDefinition('gcego-data configuration', 'authorization.ability must not be empty.');
        }

        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $cachePrefix) !== 1) {
            throw InvalidSourceException::invalidDefinition('gcego-data configuration', 'cache.prefix must be 1 to 64 letters, digits, "_", ".", ":" or "-".');
        }
    }

    /**
     * @param  array<mixed>  $config  The gcego-data configuration array.
     */
    public static function fromArray(array $config): self
    {
        $invalid = static fn (string $path, string $type): InvalidSourceException => InvalidSourceException::invalidDefinition('gcego-data configuration', sprintf('%s must be %s.', $path, $type));
        // Values from env() arrive as strings: accept digit strings for integers.
        $integer = static fn (mixed $value): mixed => is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : $value;
        $int = static function (string $path, int $default) use ($config, $invalid, $integer): int {
            $value = $integer(data_get($config, $path, $default));

            return is_int($value) ? $value : throw $invalid($path, 'an integer');
        };
        $nullableInt = static function (string $path, ?int $default) use ($config, $invalid, $integer): ?int {
            $value = $integer(data_get($config, $path, $default));

            return $value === null || $value === '' ? null : (is_int($value) ? $value : throw $invalid($path, 'an integer or null'));
        };
        $bool = static function (string $path, bool $default) use ($config, $invalid): bool {
            $value = data_get($config, $path, $default);

            return is_bool($value) ? $value : throw $invalid($path, 'a boolean');
        };
        $string = static function (string $path, ?string $default) use ($config, $invalid): ?string {
            $value = data_get($config, $path, $default);

            return $value === null || is_string($value) ? $value : throw $invalid($path, 'a string');
        };

        return new self(
            defaultPerPage: $int('pagination.default_per_page', 25),
            maxPerPage: $int('pagination.max_per_page', 100),
            searchMaxLength: $int('search.max_length', 200),
            searchMaxTerms: $int('search.max_terms', 8),
            filterMaxValues: $int('filters.max_values', 100),
            sortMaxFields: $int('sorting.max_fields', 3),
            stableOrdering: $bool('sorting.stable', true),
            strict: $bool('queries.strict', true),
            ability: $string('authorization.ability', 'gcego-data.query') ?? throw $invalid('authorization.ability', 'a string'),
            cacheEnabled: $bool('cache.enabled', true),
            cacheStore: $string('cache.store', null),
            cachePrefix: $string('cache.prefix', 'gcego-data') ?? 'gcego-data',
            cacheInvalidateOnModelEvents: $bool('cache.invalidate_on_model_events', true),
            cacheLockSeconds: $int('cache.lock_seconds', 10),
            transactionAttempts: $int('transactions.attempts', 3),
            transactionBackoffMs: $int('transactions.backoff_ms', 50),
            transactionMaxBackoffMs: $int('transactions.max_backoff_ms', 1000),
            timeoutMs: $nullableInt('performance.timeout_ms', null),
            maxOffset: $nullableInt('performance.max_offset', 100000),
            collectionMaxItems: $int('performance.collection_max_items', 10000),
            profile: $bool('performance.profile', false),
            slowQueryMs: $nullableInt('performance.slow_query_ms', 1000),
            duplicateQueryThreshold: $int('performance.duplicate_query_threshold', 5),
            preventLazyLoading: $bool('performance.prevent_lazy_loading', false),
            readEvents: $bool('events.reads', false),
        );
    }
}
