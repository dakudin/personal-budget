<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('budgets.view');
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('budgets.manage');
    }

    public function update(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id && $this->create($user);
    }
}
