<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Closure;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * @internal Parses the relation arguments of With and Includable.
 */
final class RelationPaths
{
    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, Closure|null>
     */
    public static function collect(string $node, array $arguments): array
    {
        $relations = [];

        foreach ($arguments as $argument) {
            foreach (is_array($argument) ? $argument : [$argument] as $key => $value) {
                [$path, $constraint] = is_int($key) ? [$value, null] : [$key, $value];

                if (! is_string($path) || ! ($constraint === null || $constraint instanceof Closure)) {
                    throw InvalidSourceException::invalidDefinition($node, 'relations are names, or name => constraint closure pairs.');
                }

                $relations[FieldName::assert($node, $path)] = $constraint;
            }
        }

        if ($relations === []) {
            throw InvalidSourceException::invalidDefinition($node, 'declare at least one relation.');
        }

        return $relations;
    }
}
