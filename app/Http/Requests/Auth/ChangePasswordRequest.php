<?php

namespace App\Http\Requests\Auth;

use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** @format password */
            'current_password' => ['required', 'string', new BcryptPassword],
            /** @format password */
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
