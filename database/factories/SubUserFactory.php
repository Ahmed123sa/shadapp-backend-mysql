<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mirrors database/factories/SubUserFactory.php from shadapp-backend
 * (Postgres) verbatim.
 *
 * Until now there was no factory for SubUser at all — ClientArchiveTest built
 * one with SubUser::create() by hand and said so in a comment. Every test in
 * SUBUSER_PLAN.md needs one, so it lives here.
 *
 * `permissions` defaults to an empty array rather than null on purpose: that
 * is what SubUserController::store() writes for a brand-new sub-user, and
 * hasPermission() reads a missing key as false either way. Tests that need a
 * permission granted should pass it explicitly, so the grant is visible in
 * the test body instead of hidden in this default.
 */
class SubUserFactory extends Factory
{
    protected $model = \App\Models\SubUser::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'Password1',
            'client_id' => Client::factory(),
            'permissions' => [],
        ];
    }
}
