<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

/**
 * Operations that are authorized. Values are the Laravel policy abilities used
 * for Eloquent sources; other sources pass them to the configured Gate ability.
 */
enum DataOperation: string
{
    /** Listing, filtering, searching, sorting, paginating and aggregating records. */
    case ViewAny = 'viewAny';

    /** Resolving one record by key. */
    case View = 'view';

    /** Creating a record. Checked against the model class. */
    case Create = 'create';

    /** Updating a record. Checked against the record. */
    case Update = 'update';

    /** Deleting (soft-deleting) a record. Checked against the record. */
    case Delete = 'delete';

    /** Restoring a soft-deleted record. Checked against the record. */
    case Restore = 'restore';

    /** Permanently deleting a record. Checked against the record. */
    case ForceDelete = 'forceDelete';

    public function isWrite(): bool
    {
        return ! in_array($this, [self::ViewAny, self::View], true);
    }

    /**
     * Whether the operation is authorized against a record rather than the source.
     */
    public function isRecordLevel(): bool
    {
        return ! in_array($this, [self::ViewAny, self::Create], true);
    }
}
