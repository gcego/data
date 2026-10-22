<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Gcego\Data\Tests\Fixtures\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, UUID-keyed, with a hidden attribute; like an application's institution-scoped models.
 *
 * @property string $id
 * @property string $name
 * @property string $national_id
 * @property int $institution_id
 */
final class Student extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['national_id'];

    protected static function booted(): void
    {
        // Fails closed like App\Models\Scopes\InstitutionScope: no tenant, no rows.
        self::addGlobalScope('institution', static function (Builder $query): void {
            $institution = app(Tenancy::class)->institution;

            $institution === null ? $query->whereRaw('1 = 0') : $query->where('students.institution_id', $institution);
        });
    }
}
