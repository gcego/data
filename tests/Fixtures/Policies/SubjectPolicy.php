<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Policies;

use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Models\User;

final class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subject $subject): bool
    {
        return $user->is_admin || $subject->is_published;
    }

    public function create(User $user): bool
    {
        return $user->is_admin === true;
    }

    public function update(User $user, Subject $subject): bool
    {
        return $user->is_admin === true;
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $user->is_admin === true;
    }

    public function restore(User $user, Subject $subject): bool
    {
        return $user->is_admin === true;
    }

    public function forceDelete(User $user, Subject $subject): bool
    {
        return $user->is_admin === true;
    }
}
