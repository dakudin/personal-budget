<?php

return [
    'required' => 'Поле :attribute є обов’язковим.',
    'string' => 'Поле :attribute має бути текстом.',
    'email' => 'Вкажіть коректний email.',
    'unique' => 'Значення поля :attribute вже використовується.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'min' => ['string' => 'Поле :attribute має містити щонайменше :min символів.'],
    'max' => ['string' => 'Поле :attribute має містити не більше :max символів.'],
    'attributes' => [
        'name' => 'ім’я',
        'email' => 'email',
        'password' => 'пароль',
        'current_password' => 'поточний пароль',
        'token' => 'токен відновлення',
    ],
];
