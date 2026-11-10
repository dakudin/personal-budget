<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplacePassword
{
    // The caller must hold the user's row lock in a database transaction.
    public function handle(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }
}
