<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Branches\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $branches): RedirectResponse
    {
        $request->validate(['branch_id' => ['required', 'integer']]);

        /** @var User $user */
        $user = $request->user();

        abort_unless($branches->switch($user, $request->integer('branch_id')), 403, 'Bạn không làm việc ở chi nhánh này.');

        return back();
    }
}
