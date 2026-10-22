<?php

declare(strict_types=1);

namespace Gcego\Data\Relationships;

use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;

/**
 * Checks that a dotted relation path ("topics.lessons") exists on a model,
 * by calling each public, parameterless relation method. Building a relation
 * does not query the database.
 */
final class RelationPathValidator
{
    /**
     * @param  class-string<Model>  $model
     * @return class-string<Model> The model at the end of the path.
     *
     * @throws InvalidSourceException
     */
    public static function assert(string $source, string $model, string $path): string
    {
        $current = new $model;

        foreach (explode('.', $path) as $segment) {
            $relation = null;

            if (method_exists($current, $segment)) {
                $method = new ReflectionMethod($current, $segment);

                if ($method->isPublic() && ! $method->isStatic() && $method->getNumberOfRequiredParameters() === 0) {
                    $relation = $current->{$segment}();
                }
            }

            if (! $relation instanceof Relation) {
                throw InvalidSourceException::invalid($source, sprintf('"%s" is not a relationship of %s (in "%s").', $segment, $current::class, $path));
            }

            $current = $relation->getRelated();
        }

        return $current::class;
    }
}
