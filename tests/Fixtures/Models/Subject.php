<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_published
 * @property int $level
 * @property int $institution_id
 */
final class Subject extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['is_published' => 'boolean', 'level' => 'integer'];

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function notARelation(): string
    {
        return 'value';
    }
}
