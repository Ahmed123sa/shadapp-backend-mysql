<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = \App\Models\Client::class;

    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company(),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'password' => 'password',
            'manager_id' => User::factory(),
            'status' => 'active',
            'notes' => null,
            'country' => $this->faker->country(),
            'industry' => $this->faker->word(),
            'client_type' => 'business',
            'contract_value' => 0,
            'payment_status' => 'pending',
        ];
    }
}
