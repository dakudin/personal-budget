<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends EmailRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['required', 'string', 'max:255'],
            /** @format password */
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
