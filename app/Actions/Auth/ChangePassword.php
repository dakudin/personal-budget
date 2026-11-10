<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ChangePassword
{
    public function handle(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $currentPassword, $password): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                throw ValidationException::withMessages(['current_password' => 'Поточний пароль неправильний.']);
            }

            app(ReplacePassword::class)->handle($lockedUser, $password);
            Password::deleteToken($lockedUser);
        });
    }
}
