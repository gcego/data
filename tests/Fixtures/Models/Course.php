<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A custom string primary key.
 *
 * @property string $code
 * @property string $title
 */
final class Course extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
