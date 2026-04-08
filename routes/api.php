<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotAuthController;

Route::prefix('auth/telegram/bot')->group(function () {
    Route::post('init',   [TelegramBotAuthController::class, 'init']);
    Route::get('poll',    [TelegramBotAuthController::class, 'poll']);
    Route::get('session', [TelegramBotAuthController::class, 'session']);
});
