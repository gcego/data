<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Gcego\Data\Contracts\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hospital ward: rows belong to one hospital (the tenant).
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $name
 * @property int $beds
 */
final class Ward extends Model implements BelongsToTenant
{
    protected $guarded = [];

    protected $casts = ['beds' => 'integer', 'hospital_id' => 'integer'];

    /**
     * @return HasMany<Bed, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public function tenantColumn(): string
    {
        return 'hospital_id';
    }
}
