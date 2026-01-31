<?php

use App\Http\Controllers\API\v1\AiController;
use App\Http\Controllers\API\v1\CategoryController;
use App\Http\Controllers\API\v1\FileController;
use App\Http\Controllers\API\v1\GroupController;
use App\Http\Controllers\API\v1\ImportController;
use App\Http\Controllers\API\v1\NotificationController;
use App\Http\Controllers\API\v1\PartyController;
use App\Http\Controllers\API\v1\ReminderController;
use App\Http\Controllers\API\v1\StatsController;
use App\Http\Controllers\API\v1\TransactionController;
use App\Http\Controllers\API\v1\TransferController;
use App\Http\Controllers\API\v1\UserController;
use App\Http\Controllers\API\v1\WalletController;
use App\Http\Controllers\API\VersionController;
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

// Stateless public routes are now automatically registered by the user-authentication package
Route::get('info', [VersionController::class, 'getServerInfo']);

// Stateful authenticated routes
Route::group(['prefix' => 'v1', 'middleware' => ['auth:sanctum']], function () {
    Route::group(['middleware' => ['request.body.json']], function () {
        Route::get('/user', [UserController::class, 'show']);
        Route::apiResource('groups', GroupController::class);
        Route::apiResource('parties', PartyController::class);
        Route::apiResource('wallets', WalletController::class);
        Route::apiResource('transfers', TransferController::class);

        Route::get('stats', [StatsController::class, 'index']);
    });
    Route::apiResource('transactions', TransactionController::class);
    Route::post('/transactions/{id}/files', [TransactionController::class, 'uploadFiles']);
    Route::delete('/transactions/{id}/files/{file_id}', [TransactionController::class, 'deleteFiles']);
    Route::get('/files/{id}', [FileController::class, 'show']);
    Route::post('categories/seed-defaults', [CategoryController::class, 'seedDefaults']);
    Route::apiResource('categories', CategoryController::class);

    // Import routes
    Route::post('import', [ImportController::class, 'import']);
    Route::get('imports', [ImportController::class, 'getImports']);
    Route::get('imports/{id}/failed', [ImportController::class, 'getFailedImports']);
    Route::put('imports/{id}/fix', [ImportController::class, 'fixFailedImports']);

    // AI routes
    Route::post('ai/chat', [AiController::class, 'chat']);
    Route::get('ai/health', [AiController::class, 'health']);

    // Reminder routes
    Route::apiResource('reminders', ReminderController::class);
    Route::post('reminders/{id}/snooze', [ReminderController::class, 'snooze']);
    Route::post('reminders/{id}/pause', [ReminderController::class, 'pause']);
    Route::post('reminders/{id}/resume', [ReminderController::class, 'resume']);

    // Notification routes
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('notifications/preferences', [NotificationController::class, 'getPreferences']);
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
});
