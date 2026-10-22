<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Gcego\Data\Contracts\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A bed in a ward, owned by a hospital like its ward.
 *
 * @property int $id
 * @property int $ward_id
 * @property int $hospital_id
 * @property string $label
 */
final class Bed extends Model implements BelongsToTenant
{
    protected $guarded = [];

    public function tenantColumn(): string
    {
        return 'hospital_id';
    }
}
