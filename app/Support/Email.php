<?php

namespace App\Support;

use Illuminate\Support\Str;

class Email
{
    public static function normalize(mixed $email): string
    {
        return is_string($email) ? Str::lower(trim($email)) : '';
    }
}
