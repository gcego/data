<?php

declare(strict_types=1);

namespace Gcego\Data\Performance;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks returned models (and their eager-loaded relations) so that accessing
 * a relation that was not eager loaded is a lazy-loading violation: Laravel
 * throws LazyLoadingViolationException, or calls the application's
 * Model::handleLazyLoadingViolationUsing() handler (to log instead).
 *
 * Enabled by gcego-data.performance.prevent_lazy_loading. It only affects
 * models returned by gcego/data, never the rest of the application.
 */
final class LazyLoadingGuard
{
    public static function apply(mixed $record, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return $record;
        }

        if ($record instanceof Model) {
            $record->preventsLazyLoading = true;

            foreach ($record->getRelations() as $related) {
                self::apply($related, $depth + 1);
            }
        } elseif ($record instanceof EloquentCollection) {
            foreach ($record as $model) {
                self::apply($model, $depth + 1);
            }
        }

        return $record;
    }
}
