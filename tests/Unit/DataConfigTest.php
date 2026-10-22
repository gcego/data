<?php

declare(strict_types=1);

use Gcego\Data\DataConfig;
use Gcego\Data\Exceptions\InvalidSourceException;

it('accepts integers given as strings, as env() returns them', function (): void {
    $config = DataConfig::fromArray(['performance' => ['timeout_ms' => '2500', 'max_offset' => null], 'transactions' => ['attempts' => '5']]);

    expect($config->timeoutMs)->toBe(2500)
        ->and($config->maxOffset)->toBeNull()
        ->and($config->transactionAttempts)->toBe(5);
});

it('rejects invalid write, cache and performance settings', function (array $config, string $message): void {
    expect(fn () => DataConfig::fromArray($config))->toThrow(InvalidSourceException::class, $message);
})->with([
    'no attempts' => [['transactions' => ['attempts' => 0]], 'transactions.attempts must be a positive integer'],
    'backoff above maximum' => [['transactions' => ['backoff_ms' => 2000, 'max_backoff_ms' => 100]], 'transactions.backoff_ms must be at least 0'],
    'bad prefix' => [['cache' => ['prefix' => 'has spaces']], 'cache.prefix must be'],
    'zero timeout' => [['performance' => ['timeout_ms' => 0]], 'performance.timeout_ms must be a positive integer'],
    'text timeout' => [['performance' => ['timeout_ms' => 'fast']], 'performance.timeout_ms must be an integer or null'],
]);
