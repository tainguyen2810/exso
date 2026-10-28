<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CN'.Str::upper(Str::random(5)),
            'name' => 'Chi nhánh '.fake()->city(),
            'phone' => null,
            'address' => null,
            'is_default' => false,
            'status' => 'active',
        ];
    }
}
