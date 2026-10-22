<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $topic_id
 * @property string $title
 */
final class Lesson extends Model
{
    protected $guarded = [];
}
