<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetPassword
{
    public function handle(#[\SensitiveParameter] array $credentials): void
    {
        DB::transaction(function () use ($credentials): void {
            // Serialize broker validation and token consumption for concurrent resets.
            User::where('email', $credentials['email'])->lockForUpdate()->first();

            $status = Password::reset($credentials, function (User $user, string $password): void {
                app(ReplacePassword::class)->handle($user, $password);
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });

            if ($status !== Password::PASSWORD_RESET) {
                throw ValidationException::withMessages(['email' => 'Токен недійсний або строк його дії минув. Запросіть новий.']);
            }
        });
    }
}
