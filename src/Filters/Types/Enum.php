<?php

declare(strict_types=1);

namespace Gcego\Data\Filters\Types;

use BackedEnum;
use Gcego\Data\Contracts\FilterValueType;
use Gcego\Data\Exceptions\InvalidFilterException;
use Gcego\Data\Exceptions\InvalidSourceException;

/**
 * A backed enum: only its values are accepted (cast to the backing type).
 *
 *     Filterable::make('status', [Enum::make(InvoiceStatus::class), In::make()])
 */
final readonly class Enum implements FilterValueType
{
    /**
     * @var list<string|int>
     */
    private array $values;

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function __construct(public string $enum)
    {
        $this->values = array_map(static fn (BackedEnum $case): string|int => $case->value, $enum::cases());
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     *
     * @throws InvalidSourceException
     */
    public static function make(string $enum): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw InvalidSourceException::invalidDefinition('Enum', sprintf('%s is not a backed enum.', $enum));
        }

        return new self($enum);
    }

    public function name(): string
    {
        return 'enum';
    }

    public function cast(string $field, mixed $value): string|int
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        $integers = $this->values !== [] && is_int($this->values[0]);
        $cast = match (true) {
            $integers && is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 => (int) $value,
            $integers && is_int($value) => $value,
            ! $integers && (is_string($value) || is_int($value)) => (string) $value,
            default => null,
        };

        if ($cast === null || ! in_array($cast, $this->values, true)) {
            throw InvalidFilterException::invalidValue($field, 'is not one of the allowed options.');
        }

        return $cast;
    }

    public function describe(): string
    {
        return sprintf('one of: %s', implode(', ', $this->values));
    }

    public function ordered(): bool
    {
        return false;
    }

    public function textual(): bool
    {
        return false;
    }

    public function options(): array
    {
        return $this->values;
    }
}
