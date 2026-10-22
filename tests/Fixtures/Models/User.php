<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_admin
 */
final class User extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['is_admin' => 'boolean'];
}
