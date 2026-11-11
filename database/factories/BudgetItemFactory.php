<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'category_id' => Category::factory(),
            'amount_minor' => fake()->numberBetween(0, 1_000_000),
        ];
    }
}
