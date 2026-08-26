<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalFactory extends Factory
{
    protected $model = \App\Models\Approval::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'approvable_type' => 'workspace',
            'approvable_id' => fn(array $attrs) => $attrs['workspace_id'],
            'reference_no' => 'APP-' . strtoupper($this->faker->bothify('??########')),
            'requested_by' => User::factory(),
            'status' => 'pending',
        ];
    }
}
