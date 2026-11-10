<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => null,
            'type' => TransactionType::Income,
            'amount_minor' => fake()->numberBetween(1, 1_000_000),
            'occurred_on' => fake()->date(),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function expense(): static
    {
        return $this->state(['type' => TransactionType::Expense, 'category_id' => Category::factory()]);
    }
}
