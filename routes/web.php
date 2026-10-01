<?php

use App\Http\Controllers\Api\UrlController;
use App\Http\Controllers\Api\UrlManagementController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\OwnerUrlController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});
Route::middleware('auth')->group(function (): void {
    Route::view('/account', 'account')->name('account');
    Route::prefix('/account/urls')->middleware('throttle:30,1')->group(function (): void {
        Route::get('/', [OwnerUrlController::class, 'index'])->name('account.urls.index');
        Route::post('/', [UrlController::class, 'store'])->name('account.urls.store');
        Route::prefix('/{shortCode}')->controller(UrlManagementController::class)->group(function (): void {
            Route::get('/', 'show')->name('account.urls.show');
            Route::get('/analytics', 'analytics')->name('account.urls.analytics');
            Route::post('/claim', 'claim')->middleware('verified')->name('account.urls.claim');
            Route::patch('/', 'update')->name('account.urls.update');
            Route::post('/disable', 'disable')->name('account.urls.disable');
            Route::post('/enable', 'enable')->name('account.urls.enable');
            Route::delete('/', 'destroy')->name('account.urls.destroy');
        });
    });
    Route::prefix('/account/api-keys')->middleware('verified')->controller(ApiKeyController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('throttle:api-keys.list')->name('account.api-keys.index');
        Route::post('/', 'store')->middleware('throttle:api-keys.create')->name('account.api-keys.store');
        Route::delete('/{apiKey}', 'destroy')->middleware('throttle:api-keys.revoke')->name('account.api-keys.destroy');
    });
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:verification')
        ->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
Route::get('/{shortCode}', [UrlController::class, 'redirect']);
