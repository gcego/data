<?php

declare(strict_types=1);

namespace Gcego\Data\Filters;

use BackedEnum;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * The only values a filter accepts (after casting by its value type):
 *
 *     Filterable::make('status', [StringType::make(), In::make(), Options::make(['draft', 'published'])])
 */
final readonly class Options implements FilterOption
{
    /**
     * @param  list<scalar|BackedEnum>  $values
     */
    private function __construct(public array $values) {}

    /**
     * @param  list<scalar|BackedEnum>  $values
     *
     * @throws InvalidSourceException
     */
    public static function make(array $values): self
    {
        if ($values === [] || ! array_is_list($values)) {
            throw InvalidSourceException::invalidDefinition('Options', 'declare a non-empty list of values.');
        }

        return new self($values);
    }
}
