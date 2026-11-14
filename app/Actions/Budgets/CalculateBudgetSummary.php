<?php

namespace App\Actions\Budgets;

use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalculateBudgetSummary
{
    public function handle(User $user, string $month): Collection
    {
        // Read the plan and its aggregates from one consistent MySQL snapshot.
        return DB::transaction(function () use ($user, $month): Collection {
            $budget = $user->budgets()->where('month', $month)->with('items')->first();
            $plan = $budget?->items->keyBy('category_id') ?? collect();
            // MySQL DATE ends in 9999; this valid DATETIME bound includes its final day.
            $upperBound = $month === '9999-12-01'
                ? '9999-12-31 23:59:59'
                : CarbonImmutable::parse($month)->addMonth()->format('Y-m-d');
            $actual = $user->transactions()->where('occurred_on', '>=', $month)
                ->where('occurred_on', '<', $upperBound)
                ->selectRaw('type, category_id, SUM(amount_minor) AS total_minor')
                ->groupBy('type', 'category_id')->get();
            $expenses = $actual->where('type.value', 'expense')->keyBy('category_id');
            $categories = Category::whereIn('id', $plan->keys()->merge($expenses->keys())->unique())->orderBy('id')->get();
            $items = $categories->map(function (Category $category) use ($plan, $expenses): Collection {
                $planned = (int) ($plan->get($category->id)?->amount_minor ?? 0);
                $spent = (int) ($expenses->get($category->id)?->total_minor ?? 0);

                return collect([
                    'category' => $category,
                    'is_planned' => $plan->has($category->id),
                    'planned_minor' => $planned,
                    'spent_minor' => $spent,
                    'remaining_minor' => $planned - $spent,
                    'overspent_minor' => max($spent - $planned, 0),
                    'used_percent' => $planned === 0 ? null : round($spent / $planned * 100, 2),
                ]);
            });
            $planned = (int) $plan->sum('amount_minor');
            $spent = (int) $items->sum('spent_minor');
            $income = (int) $actual->where('type.value', 'income')->sum(fn ($row): int => (int) $row->total_minor);

            return collect([
                'month' => substr($month, 0, 7),
                'has_budget' => $budget !== null,
                'planned_total_minor' => $planned,
                'spent_total_minor' => $spent,
                'remaining_total_minor' => $planned - $spent,
                'overspent_total_minor' => max($spent - $planned, 0),
                'unplanned_spent_minor' => (int) $items->where('is_planned', false)->sum('spent_minor'),
                'income_total_minor' => $income,
                'net_total_minor' => $income - $spent,
                'items' => $items,
            ]);
        });
    }
}
