<?php

namespace App\Http\Requests\Auth;

class VerifyEmailCodeRequest extends EmailRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Вкажіть email.',
            'email.string' => 'Email має бути рядком.',
            'email.email' => 'Вкажіть коректний email.',
            'email.max' => 'Email не може містити більше 255 символів.',
            'code.required' => 'Вкажіть код підтвердження.',
            'code.string' => 'Код має бути рядком із шести цифр.',
            'code.regex' => 'Код має містити рівно шість цифр від 0 до 9.',
        ];
    }
}
