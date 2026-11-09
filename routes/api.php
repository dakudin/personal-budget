<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->controller(AuthController::class)->group(function (): void {
        Route::post('register', 'register')->middleware('throttle:auth-register');
        Route::post('login', 'login')->middleware('throttle:auth-login');
        Route::post('forgot-password', 'forgotPassword')->middleware('throttle:auth-recovery');
        Route::post('reset-password', 'resetPassword')->middleware('throttle:auth-recovery');
        Route::post('logout', 'logout')->middleware('auth:sanctum');
        Route::put('password', 'changePassword')->middleware(['auth:sanctum', 'throttle:auth-recovery']);
    });
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});
