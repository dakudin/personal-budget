<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Middleware\EnsureApiEmailIsVerified;
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
    Route::prefix('auth/email')->controller(EmailVerificationController::class)->group(function (): void {
        Route::get('verify', 'notice')->middleware('auth:sanctum')->name('verification.notice');
        Route::post('verify', 'verify')->middleware('throttle:email-verify')->name('verification.verify');
        Route::post('verification-notification', 'send')->middleware(['auth:sanctum', 'throttle:email-verification-send'])->name('verification.send');
    });
    Route::middleware(['auth:sanctum', EnsureApiEmailIsVerified::class])->group(function (): void {
        Route::get('budgets/{month}', [BudgetController::class, 'show']);
        Route::put('budgets/{month}', [BudgetController::class, 'update']);
        Route::get('budgets/{month}/summary', [BudgetController::class, 'summary']);
        Route::get('categories', [CategoryController::class, 'index']);
        Route::apiResource('transactions', TransactionController::class)->except('update');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update']);
    });
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});
