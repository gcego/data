<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Resources;

use Gcego\Data\Tests\Fixtures\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subject
 */
final class SubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['label' => $this->name.' ('.$this->code.')'];
    }
}
