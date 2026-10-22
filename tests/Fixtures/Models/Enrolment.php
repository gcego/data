<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $status
 */
final class Enrolment extends Model
{
    use HasUlids;

    protected $guarded = [];
}
