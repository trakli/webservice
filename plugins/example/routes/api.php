<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Trakli\Example\Http\Controllers\ExampleController;

/*
|--------------------------------------------------------------------------
| Plugin API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your plugin. These
| routes are loaded by the ExampleServiceProvider and will be
| assigned to the 'api' middleware group.
|
*/

// Public routes
Route::get('/', [ExampleController::class, 'index'])->name('index');

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/protected', [ExampleController::class, 'protectedData'])->name('protected');
});

// Example resource routes
// Route::apiResource('examples', ExampleController::class);
