<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveBudget
{
    public function handle(User $user, string $month, array $items): Budget
    {
        return DB::transaction(function () use ($user, $month, $items): Budget {
            // A stable parent row serializes saves even before the budget exists.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $budget = $user->budgets()->where('month', $month)->lockForUpdate()->first();
            Gate::forUser($user)->authorize($budget ? 'update' : 'create', $budget ?? Budget::class);

            $existing = $budget?->items()->pluck('category_id')->all() ?? [];
            // Lock categories in a stable order so activation cannot change during validation.
            $categories = Category::whereIn('id', array_column($items, 'category_id'))
                ->orderBy('id')->sharedLock()->get()->keyBy('id');

            foreach ($items as $index => $item) {
                $category = $categories->get($item['category_id']);
                if (! $category || (! $category->is_active && ! in_array($category->id, $existing, true))) {
                    throw ValidationException::withMessages([
                        "items.$index.category_id" => 'Додавати до плану можна лише активні категорії.',
                    ]);
                }
            }

            $budget ??= $user->budgets()->create(['month' => $month]);
            $budget->items()->delete();
            $budget->items()->createMany($items);
            $budget->touch();

            return $budget->load('items.category');
        }, attempts: 3);
    }
}
