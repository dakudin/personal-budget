<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Email;
use App\Support\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifyEmailCode
{
    public function handle(string $email, #[\SensitiveParameter] string $code): void
    {
        $verified = DB::transaction(function () use ($email, $code): bool {
            $user = User::where('email', Email::normalize($email))->lockForUpdate()->first();

            if (! $user || $user->hasVerifiedEmail() || ! $user->email_verification_code_hash
                || ! $user->email_verification_code_expires_at?->isFuture()
                || $user->email_verification_code_attempts >= 5) {
                return false;
            }

            if (! EmailVerificationCode::matches($user->email, $code, $user->email_verification_code_hash)) {
                $user->forceFill(['email_verification_code_attempts' => $user->email_verification_code_attempts + 1])->save();

                return false;
            }

            $user->forceFill([
                'email_verification_code_hash' => null,
                'email_verification_code_expires_at' => null,
                'email_verification_code_attempts' => 0,
            ]);
            $user->markEmailAsVerified();
            DB::afterCommit(fn () => event(new Verified($user)));

            return true;
        });

        // Raise validation errors after committing persisted failed attempts.
        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'Код недійсний або строк його дії минув. Запросіть новий']);
        }
    }
}
