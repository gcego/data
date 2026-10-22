<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Closure;
use Gcego\Data\Contracts\SourceConfiguration;

/**
 * An in-memory collection source (settings, enum lists, API responses already
 * fetched). Every execution loads every item, so keep it small and bounded:
 *
 *     Items::make(fn () => config('hospital.wards'))
 *     Items::make([['id' => 1, 'name' => 'Cardiology']])
 *
 * Collection sources support filtering, search, sorting and page pagination
 * in PHP; not cursors, relationships or writes.
 */
final readonly class Items implements SourceConfiguration
{
    /**
     * @param  Closure(): iterable<mixed>  $factory
     */
    private function __construct(public Closure $factory) {}

    /**
     * @param  Closure(): iterable<mixed>|iterable<mixed>  $items
     */
    public static function make(Closure|iterable $items): self
    {
        return new self($items instanceof Closure ? $items : static fn (): iterable => $items);
    }

    /**
     * @return array{items: string}
     */
    public function normalize(): array
    {
        return ['items' => 'closure'];
    }
}
