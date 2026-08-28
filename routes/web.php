<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\Api\UrlController;

Route::get('/{shortCode}', [UrlController::class, 'redirect']);
