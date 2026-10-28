<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The branch the signed-in user is working in, kept in the session and
 * always one of the branches the user is allowed to use.
 */
class CurrentBranch
{
    public function __construct(protected Session $session) {}

    public function id(User $user): ?int
    {
        $allowed = $user->branchIds();
        $selected = $this->session->get('branch_id');

        if (is_int($selected) && in_array($selected, $allowed, true)) {
            return $selected;
        }

        $default = Branch::query()->whereIn('id', $allowed)->orderByDesc('is_default')->orderBy('id')->value('id');

        return $default === null ? null : (int) $default;
    }

    public function get(User $user): ?Branch
    {
        $id = $this->id($user);

        return $id === null ? null : Branch::query()->find($id);
    }

    public function switch(User $user, int $branchId): bool
    {
        if (! in_array($branchId, $user->branchIds(), true)) {
            return false;
        }

        $this->session->put('branch_id', $branchId);

        return true;
    }
}
