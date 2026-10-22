<?php

declare(strict_types=1);

namespace Gcego\Data\Definitions;

use Gcego\Data\Contracts\SourceConfiguration;
use Gcego\Data\Exceptions\InvalidSourceException;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * An Eloquent model source: Model::make(Employee::class).
 *
 * The class is not autoloaded when the source is defined; it is checked when
 * the source is compiled (first use or Data::inspect()).
 */
final readonly class Model implements SourceConfiguration
{
    /**
     * @param  class-string<EloquentModel>  $class
     */
    private function __construct(public string $class) {}

    /**
     * @param  class-string<EloquentModel>  $class
     *
     * @throws InvalidSourceException
     */
    public static function make(string $class): self
    {
        /** @var class-string<EloquentModel> $normalized */
        $normalized = ltrim($class, '\\');

        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) === 1
            ? new self($normalized)
            : throw InvalidSourceException::invalidDefinition('Model', sprintf('"%s" is not a class name.', mb_substr($class, 0, 80)));
    }

    /**
     * @return array{model: string}
     */
    public function normalize(): array
    {
        return ['model' => $this->class];
    }
}
