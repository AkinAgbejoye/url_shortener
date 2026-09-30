<?php

use App\Http\Controllers\Api\UrlController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function (): void {
    Route::view('/account', 'account')->name('account');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
Route::get('/{shortCode}', [UrlController::class, 'redirect']);
