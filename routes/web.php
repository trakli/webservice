<?php

use App\Http\Controllers\MailPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    $host = request()->getHttpHost();

    return response()->json(['welcome' => 'Welcome to the Trakli WebService! See API documentation here: '."$host/docs/swagger or $host/docs/api.json"]);
});

if (config('app.debug') && app()->environment(['local', 'staging', 'testing'])) {
    Route::prefix('dev')->group(function () {
        Route::get('/mail-preview', [MailPreviewController::class, 'index'])->name('mail-preview.index');
        Route::get('/mail-preview/{type}', [MailPreviewController::class, 'show'])->name('mail-preview.show');
    });
}
