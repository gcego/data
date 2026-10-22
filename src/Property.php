<?php

declare(strict_types=1);

namespace Gcego\Data;

use Gcego\Data\Exceptions\InvalidSourceException;
use Gcego\Data\Support\Callback;
use UnitEnum;

/**
 * One immutable setting of a Source, the advanced form of its constructor:
 *
 *     new Source(new Property(Source::Model, Subject::class), new Property(Source::Pagination, 25));
 *
 * Values are restricted to null, scalars, enums, Callback instances and
 * arrays of those, so a Source stays a plain, inspectable description.
 * Whether a value suits its key is checked when the Source is compiled.
 */
final readonly class Property
{
    public const string KeyPattern = '/\A[A-Za-z_][A-Za-z0-9_.\-]{0,127}\z/';

    private const int MaxArrayDepth = 16;

    /**
     * @throws InvalidSourceException
     */
    public function __construct(
        public string $key,
        public mixed $value = null,
    ) {
        if (preg_match(self::KeyPattern, $key) !== 1) {
            throw InvalidSourceException::invalidDefinition('Property', sprintf('"%s" is not a valid property key.', mb_substr($key, 0, 80)));
        }

        if (! self::isSupported($value, 0)) {
            throw InvalidSourceException::invalidDefinition('Property', sprintf('the value of "%s" must be null, a scalar, an enum, a Callback or an array of those.', $key));
        }
    }

    private static function isSupported(mixed $value, int $depth): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum || $value instanceof Callback) {
            return true;
        }

        if (! is_array($value) || $depth >= self::MaxArrayDepth) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isSupported($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
