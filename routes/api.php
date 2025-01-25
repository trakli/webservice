<?php

use App\Http\Controllers\API\v1\Auth\AuthController;
use App\Http\Controllers\API\v1\Auth\PasswordResetController;
use App\Http\Controllers\API\v1\CategoryController;
use App\Http\Controllers\API\v1\GroupController;
use App\Http\Controllers\API\v1\PartyController;
use App\Http\Controllers\API\v1\TransactionController;
use App\Http\Controllers\API\v1\UserController;
use App\Http\Controllers\API\v1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Auth routes
Route::group(['prefix' => 'v1', 'middleware' => ['request.body.json']], function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/reset-code', [PasswordResetController::class, 'sendPasswordResetCode']);
    Route::post('/password/reset', [PasswordResetController::class, 'resetPasswordWithCode']);
});

// Resource routes
Route::group(['prefix' => 'v1', 'middleware' => ['request.body.json']], function () {
    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::get('/user', [UserController::class, 'show']);
        Route::apiResource('groups', GroupController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('parties', PartyController::class);
        Route::apiResource('wallets', WalletController::class);
        Route::apiResource('transactions', TransactionController::class);
    });
});
