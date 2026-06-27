<?php

use App\Http\Controllers\API\v1\AccountController;
use App\Http\Controllers\API\v1\Admin\MetricsController as AdminMetricsController;
use App\Http\Controllers\API\v1\Admin\OutreachController as AdminOutreachController;
use App\Http\Controllers\API\v1\Admin\UserController as AdminUserController;
use App\Http\Controllers\API\v1\AiController;
use App\Http\Controllers\API\v1\BudgetController;
use App\Http\Controllers\API\v1\BudgetPeriodStateController;
use App\Http\Controllers\API\v1\CategoryController;
use App\Http\Controllers\API\v1\FileController;
use App\Http\Controllers\API\v1\GroupController;
use App\Http\Controllers\API\v1\ImportController;
use App\Http\Controllers\API\v1\IntegrationController;
use App\Http\Controllers\API\v1\NotificationController;
use App\Http\Controllers\API\v1\PartyController;
use App\Http\Controllers\API\v1\ReminderController;
use App\Http\Controllers\API\v1\StatsController;
use App\Http\Controllers\API\v1\TransactionController;
use App\Http\Controllers\API\v1\TransactionRefundController;
use App\Http\Controllers\API\v1\TransferController;
use App\Http\Controllers\API\v1\UserController;
use App\Http\Controllers\API\v1\AssetPriceController;
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
        Route::delete('/account', [AccountController::class, 'destroy']);
        Route::apiResource('groups', GroupController::class);
        Route::apiResource('parties', PartyController::class);
        Route::apiResource('wallets', WalletController::class);
        Route::apiResource('transfers', TransferController::class);

        Route::get('asset-prices/search', [AssetPriceController::class, 'search']);

        Route::get('stats', [StatsController::class, 'index']);
    });
    Route::get('integrations', [IntegrationController::class, 'index']);
    Route::apiResource('transactions', TransactionController::class);
    Route::post('/transactions/{id}/files', [TransactionController::class, 'uploadFiles']);
    Route::delete('/transactions/{id}/files/{file_id}', [TransactionController::class, 'deleteFiles']);
    Route::get('/refunds', [TransactionRefundController::class, 'index']);
    Route::post('/transactions/{id}/refund', [TransactionRefundController::class, 'mark']);
    Route::delete('/transactions/{id}/refund', [TransactionRefundController::class, 'unmark']);
    Route::get('/files/{id}', [FileController::class, 'show']);
    Route::post('categories/seed-defaults', [CategoryController::class, 'seedDefaults']);
    Route::apiResource('categories', CategoryController::class);

    // Advanced import routes (analyze → review → confirm)
    Route::post('import/analyze', [ImportController::class, 'analyze']);
    Route::post('import/confirm', [ImportController::class, 'confirm']);
    Route::get('import/sessions', [ImportController::class, 'getSessions']);
    Route::get('import/sessions/{id}', [ImportController::class, 'getSession']);
    Route::delete('import/sessions/{id}', [ImportController::class, 'destroySession']);

    // Import routes (legacy CSV auto-import)
    Route::post('import', [ImportController::class, 'import']);
    Route::get('imports', [ImportController::class, 'getImports']);
    Route::get('imports/{id}/failed', [ImportController::class, 'getFailedImports']);
    Route::put('imports/{id}/fix', [ImportController::class, 'fixFailedImports']);

    // AI routes
    Route::get('ai/chats', [AiController::class, 'index']);
    Route::post('ai/chats', [AiController::class, 'store']);
    Route::get('ai/chats/{chat}', [AiController::class, 'show']);
    Route::delete('ai/chats/{chat}', [AiController::class, 'destroy']);
    Route::post('ai/chats/{chat}/messages', [AiController::class, 'storeMessage']);
    Route::post('ai/chats/{chat}/messages/{message}/files', [AiController::class, 'uploadFiles']);
    Route::get('ai/chats/{chat}/messages/{message}/export', [AiController::class, 'exportCanvas']);
    Route::post('ai/chats/{chat}/actions/{action}/confirm', [AiController::class, 'confirmAction']);
    Route::post('ai/chats/{chat}/actions/{action}/reject', [AiController::class, 'rejectAction']);
    Route::get('ai/health', [AiController::class, 'health']);

    // Reminder routes
    Route::apiResource('reminders', ReminderController::class);
    Route::post('reminders/{id}/snooze', [ReminderController::class, 'snooze']);
    Route::post('reminders/{id}/pause', [ReminderController::class, 'pause']);
    Route::post('reminders/{id}/resume', [ReminderController::class, 'resume']);

    // Budget routes
    Route::get('budgets/{id}/progress', [BudgetController::class, 'progress']);
    Route::get('budgets/{id}/transactions', [BudgetController::class, 'transactions']);
    Route::post('budgets/{id}/close-period', [BudgetController::class, 'closePeriod']);
    Route::get('budget-period-states', [BudgetPeriodStateController::class, 'index']);
    Route::apiResource('budgets', BudgetController::class);

    // Notification routes
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('notifications/preferences', [NotificationController::class, 'getPreferences']);
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

    // Admin routes
    Route::group(['prefix' => 'admin', 'middleware' => ['role:admin']], function () {
        Route::get('metrics', [AdminMetricsController::class, 'show']);
        Route::get('outreach', [AdminOutreachController::class, 'index']);
        Route::post('outreach/preview', [AdminOutreachController::class, 'preview']);
        Route::post('outreach/media', [AdminOutreachController::class, 'media']);
        Route::post('outreach/send', [AdminOutreachController::class, 'send']);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{id}', [AdminUserController::class, 'show']);
        Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
    });
});
