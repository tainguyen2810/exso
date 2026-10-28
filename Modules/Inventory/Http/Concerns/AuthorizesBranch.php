<?php

namespace Modules\Inventory\Http\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait AuthorizesBranch
{
    /**
     * Documents of branches the user does not work at are not theirs to see.
     */
    protected function authorizeBranch(Request $request, int ...$branchIds): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(array_intersect($branchIds, $user->branchIds()) === [], 403, 'Bạn không làm việc ở chi nhánh này.');
    }
}
