<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use App\Support\EmailVerificationCode;
use Illuminate\Support\Facades\DB;

class SendEmailVerificationCode
{
    public function handle(User $recipient): bool
    {
        return DB::transaction(function () use ($recipient): bool {
            $user = User::whereKey($recipient->getKey())->lockForUpdate()->firstOrFail();

            if ($user->hasVerifiedEmail()) {
                return false;
            }

            do {
                $code = EmailVerificationCode::generate();
            } while ($user->email_verification_code_hash
                && EmailVerificationCode::matches($user->email, $code, $user->email_verification_code_hash));

            $expiresInMinutes = (int) config('auth.verification.expire');
            $expiresAt = now()->toImmutable()->startOfSecond()->addMinutes($expiresInMinutes);
            $hash = EmailVerificationCode::hash($user->email, $code);
            $user->forceFill([
                'email_verification_code_hash' => $hash,
                'email_verification_code_expires_at' => $expiresAt,
                'email_verification_code_attempts' => 0,
            ])->save();
            $user->notify(new VerifyEmailCode($code, $user->email, $hash, $expiresAt, $expiresInMinutes));

            return true;
        });
    }
}
