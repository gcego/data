<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Closure;
use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\FieldName;

/**
 * @internal Parses relation lists (Relations, Includables) and bounds relation depth.
 */
final class RelationPaths
{
    /** Most relations one path may traverse ("reports.department.manager"). */
    public const int MaxDepth = 3;

    /**
     * @param  array<array-key, mixed>  $relations
     * @return array<string, Closure|null>
     *
     * @throws InvalidSourceException
     */
    public static function collect(string $node, array $relations): array
    {
        $paths = [];

        foreach ($relations as $key => $value) {
            [$path, $constraint] = is_int($key) ? [$value, null] : [$key, $value];

            if (! is_string($path) || ! ($constraint === null || $constraint instanceof Closure)) {
                throw InvalidSourceException::invalidDefinition($node, 'relations are names, or name => constraint closure pairs.');
            }

            $paths[self::assertDepth($node, FieldName::assert($node, $path))] = $constraint;
        }

        if ($paths === []) {
            throw InvalidSourceException::invalidDefinition($node, 'declare at least one relation.');
        }

        return $paths;
    }

    /**
     * @param  bool  $column  Whether the path ends with a column ("department.name").
     *
     * @throws InvalidSourceException
     */
    public static function assertDepth(string $node, string $path, bool $column = false): string
    {
        $relations = substr_count($path, '.') + ($column ? 0 : 1);

        return $relations > self::MaxDepth
            ? throw InvalidSourceException::invalidDefinition($node, sprintf('"%s" traverses more than %d relations.', $path, self::MaxDepth))
            : $path;
    }
}
