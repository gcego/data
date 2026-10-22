<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Policies;

use Gcego\Data\Tests\Fixtures\Models\User;
use Gcego\Data\Tests\Fixtures\Models\Ward;

final class WardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ward $ward): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ward $ward): bool
    {
        return true;
    }

    public function delete(User $user, Ward $ward): bool
    {
        return true;
    }
}
