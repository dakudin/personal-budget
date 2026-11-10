<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('categories.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('categories.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('categories.manage');
    }

    public function delete(User $user, Category $category): Response
    {
        if (! $user->can('categories.manage')) {
            return Response::deny();
        }

        return $category->transactions()->exists()
            ? Response::deny('Категорія використовується в операціях. Деактивуйте її, щоб зберегти історію.')
            : Response::allow();
    }
}
