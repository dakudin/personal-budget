<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BcryptPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt rejects NUL bytes and silently truncates inputs after 72 bytes.
        if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('Пароль має містити не більше 72 байтів UTF-8 і не може містити NUL-байт.');
        }
    }
}
