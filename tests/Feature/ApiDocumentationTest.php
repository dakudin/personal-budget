<?php

use Dedoc\Scramble\Generator;

it('documents authentication endpoints and their security requirements', function () {
    $document = app(Generator::class)();

    expect($document['paths'])->toHaveCount(14)
        ->and($document['components']['securitySchemes']['bearerAuth'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($document['security'])->toBe([['bearerAuth' => []]]);

    foreach (['register' => 201, 'login' => 200, 'forgot-password' => 202, 'reset-password' => 204] as $name => $status) {
        $operation = $document['paths']['/v1/auth/'.$name]['post'];
        expect($operation['security'])->toBe([])
            ->and($operation['responses'])->toHaveKeys([$status, 422, 429])
            ->and($operation['requestBody']['required'])->toBeTrue();
    }

    foreach (['/v1/me' => 'get', '/v1/auth/logout' => 'post', '/v1/auth/password' => 'put'] as $path => $method) {
        expect($document['paths'][$path][$method]['security'] ?? $document['security'])->toBe([['bearerAuth' => []]])
            ->and($document['paths'][$path][$method]['responses'])->toHaveKey(401);
    }

    $forgotResponse = $document['paths']['/v1/auth/forgot-password']['post']['responses'][202];
    expect($forgotResponse['content']['application/json']['schema']['properties'])->toHaveKeys(['message'])
        ->not->toHaveKey('token');
    foreach (['forgot-password', 'reset-password'] as $name) {
        expect($document['paths']['/v1/auth/'.$name]['post']['responses'])->not->toHaveKey(401);
    }

    $schemas = $document['components']['schemas'];
    expect($schemas['RegisterRequest']['required'])->toContain('name', 'email', 'password', 'password_confirmation')
        ->and($schemas['ResetPasswordRequest']['required'])->toContain('token', 'email', 'password', 'password_confirmation')
        ->and($schemas['ChangePasswordRequest']['required'])->toContain('current_password', 'password', 'password_confirmation')
        ->and($schemas['AuthTokenResource']['required'])->toContain('user', 'token', 'token_type', 'expires_at')
        ->and($schemas['UserResource']['properties'])->not->toHaveKeys(['password', 'remember_token']);
});

it('documents public code verification and authenticated status and resend', function () {
    $document = app(Generator::class)();
    foreach (['/v1/auth/email/verify' => 'get', '/v1/auth/email/verification-notification' => 'post'] as $path => $method) {
        $operation = $document['paths'][$path][$method];
        expect($operation['security'] ?? $document['security'])->toBe([['bearerAuth' => []]])
            ->and($operation['responses'])->toHaveKeys([200, 401]);
    }

    $verify = $document['paths']['/v1/auth/email/verify']['post'];
    expect($verify['security'])->toBe([])
        ->and($verify['responses'])->toHaveKeys([200, 422, 429])->not->toHaveKeys([401, 403])
        ->and($document['paths'])->not->toHaveKey('/v1/auth/email/verify/{id}/{hash}')
        ->and($document['components']['schemas']['VerifyEmailCodeRequest']['required'])->toContain('email', 'code')
        ->and($document['components']['schemas']['VerifyEmailCodeRequest']['properties']['code']['type'])->toBe('string')
        ->and($document['paths']['/v1/auth/email/verification-notification']['post']['responses'])->toHaveKeys([202, 429]);
});

it('documents financial endpoints filters resources and errors', function () {
    $document = app(Generator::class)();
    $paths = $document['paths'];
    expect($paths['/v1/categories']['get']['tags'])->toBe(['Категорії']);
    $categoryParameters = collect($paths['/v1/categories']['get']['parameters'])->keyBy('name');
    expect($categoryParameters['include_inactive']['schema']['type'])->toBe('boolean')
        ->and($categoryParameters['include_inactive']['schema']['default'])->toBeFalse();
    $parameters = collect($paths['/v1/transactions']['get']['parameters'])->keyBy('name');
    expect($parameters->keys()->all())->toContain('date_from', 'date_to', 'type', 'category_id', 'per_page', 'page')
        ->and($parameters['per_page']['schema'])->toMatchArray(['minimum' => 1, 'maximum' => 100, 'default' => 20]);
    foreach ([['/v1/categories', 'get', 200], ['/v1/transactions', 'get', 200], ['/v1/transactions', 'post', 201], ['/v1/transactions/{transaction}', 'get', 200], ['/v1/transactions/{transaction}', 'patch', 200], ['/v1/transactions/{transaction}', 'delete', 204]] as [$path, $method, $status]) {
        $operation = $paths[$path][$method];
        expect($operation['security'] ?? $document['security'])->toBe([['bearerAuth' => []]])
            ->and($operation['responses'])->toHaveKeys([$status, 401, 403]);
        if (str_contains($path, '{transaction}')) {
            expect($operation['responses'])->toHaveKey(404);
        }
        if (! in_array($method, ['get', 'delete']) || ! str_contains($path, '{transaction}')) {
            expect($operation['responses'])->toHaveKey(422);
        }
    }
    $schemas = $document['components']['schemas'];
    expect($schemas['StoreTransactionRequest']['properties']['amount_minor'])->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 999999999999])
        ->and($schemas['StoreTransactionRequest']['required'])->toContain('type', 'amount_minor', 'occurred_on')
        ->and($schemas['UpdateTransactionRequest']['required'] ?? [])->toBe([])
        ->and($schemas['TransactionResource']['properties']['currency']['const'])->toBe('UAH')
        ->and($schemas['TransactionResource']['properties']['occurred_on']['format'])->toBe('date')
        ->and($schemas['TransactionResource']['properties'])->toHaveKeys(['amount_minor', 'category'])
        ->and($schemas['CategoryResource']['properties'])->toHaveKeys(['id', 'name', 'is_active']);
});

it('documents budgets permissions month examples replacement and summary schemas', function () {
    $document = app(Generator::class)();
    foreach ([['/v1/budgets/{month}', 'get'], ['/v1/budgets/{month}', 'put'], ['/v1/budgets/{month}/summary', 'get']] as [$path, $method]) {
        $operation = $document['paths'][$path][$method];
        expect($operation['tags'])->toBe(['Бюджети'])
            ->and($operation['responses'])->toHaveKeys([200, 401, 403, 422])
            ->and($operation['security'] ?? $document['security'])->toBe([['bearerAuth' => []]]);
        $month = collect($operation['parameters'])->firstWhere('name', 'month');
        expect($month)->toMatchArray(['in' => 'path', 'required' => true, 'example' => '2026-11'])
            ->and($month['schema']['type'])->toBe('string');
    }
    expect($document['paths']['/v1/budgets/{month}']['put']['responses'])->toHaveKey(201)
        ->and($document['paths']['/v1/budgets/{month}']['get']['responses'])->toHaveKey(404);
    $schemas = $document['components']['schemas'];
    expect($schemas['SaveBudgetRequest']['required'])->toContain('items')
        ->and($schemas['SaveBudgetRequest']['properties']['items']['type'])->toBe('array')
        ->and($schemas['SaveBudgetRequest']['properties']['items']['items']['properties']['amount_minor'])->toMatchArray(['type' => 'integer', 'minimum' => 0, 'maximum' => 999999999999])
        ->and($schemas['BudgetResource']['properties']['currency']['const'])->toBe('UAH')
        ->and($schemas['BudgetSummaryResource']['properties'])->toHaveKeys(['has_budget', 'planned_total_minor', 'spent_total_minor', 'remaining_total_minor', 'overspent_total_minor', 'unplanned_spent_minor', 'income_total_minor', 'net_total_minor', 'items'])
        ->and($schemas['BudgetSummaryItemResource']['properties'])->toHaveKeys(['category', 'is_planned', 'planned_minor', 'spent_minor', 'remaining_minor', 'overspent_minor', 'used_percent']);
});
