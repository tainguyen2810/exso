<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get a fresh database and a default store (tenant).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A store owner working in a default branch.
 */
function owner(): User
{
    $branch = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);

    $user = User::factory()->owner()->create();
    $user->branches()->attach($branch);

    return $user;
}

/**
 * A staff member with one of the default roles ("Thu ngân", "Nhân viên kho", ...).
 */
function staffWithRole(string $role, ?Branch $branch = null): User
{
    $branch ??= Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);

    $user = User::factory()->create();
    $user->branches()->attach($branch);
    $user->assignRole($role);

    return $user;
}
