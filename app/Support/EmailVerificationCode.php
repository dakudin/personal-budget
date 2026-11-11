<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

class EmailVerificationCode
{
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hash(string $email, #[\SensitiveParameter] string $code): string
    {
        return Hash::make(self::payload($email, $code));
    }

    public static function matches(string $email, #[\SensitiveParameter] string $code, string $hash): bool
    {
        return Hash::check(self::payload($email, $code), $hash);
    }

    private static function payload(string $email, #[\SensitiveParameter] string $code): string
    {
        // Prehash the email and code to stay within bcrypt's input length limit.
        return hash('sha256', Email::normalize($email).'|'.$code);
    }
}
