<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkspaceFactory extends Factory
{
    protected $model = \App\Models\Workspace::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'manager_id' => User::factory(),
            'status' => 'active',
        ];
    }
}
