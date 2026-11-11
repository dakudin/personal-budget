<?php

namespace App\Http\Requests\Auth;

use App\Rules\BcryptPassword;

class LoginRequest extends EmailRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'password' => ['required', 'string', new BcryptPassword]];
    }
}
