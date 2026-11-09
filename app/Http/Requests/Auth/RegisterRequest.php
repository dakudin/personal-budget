<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

class RegisterRequest extends EmailRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [...parent::rules()['email'], 'unique:users,email'],
            /** @format password */
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
