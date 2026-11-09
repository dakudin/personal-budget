<?php

use Dedoc\Scramble\Generator;

it('documents all seven authentication endpoints and their security requirements', function () {
    $document = app(Generator::class)();

    expect($document['paths'])->toHaveCount(7)
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
