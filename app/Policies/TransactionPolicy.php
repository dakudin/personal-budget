<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('transactions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('transactions.create');
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id && $user->can('transactions.view');
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id && $user->can('transactions.update');
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id && $user->can('transactions.delete');
    }
}
