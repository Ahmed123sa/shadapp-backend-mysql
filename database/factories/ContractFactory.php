<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = \App\Models\Contract::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => $this->faker->sentence(3),
            'status' => 'draft',
            'value' => 10000,
            'currency' => 'SAR',
            'start_date' => $this->faker->dateTimeBetween('-10 days', '+10 days'),
            'end_date' => $this->faker->dateTimeBetween('+30 days', '+120 days'),
        ];
    }

    public function withCreator(User $user): static
    {
        return $this->state(fn() => ['created_by' => $user->id]);
    }
}
