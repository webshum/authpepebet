<?php

use App\Http\Controllers\TelegramBotAuthController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::group(['prefix' => 'auth'], function () {
    Route::get('google', [AuthController::class, 'redirect']);
    Route::get('google/callback', [AuthController::class, 'callback']);

    Route::get('vkontakte', [AuthController::class, 'redirect']);
    Route::get('vkontakte/callback', [AuthController::class, 'callback']);

    // Route::get('telegram', [AuthController::class, 'redirect']);
    // Route::get('telegram/callback', [AuthController::class, 'callback']);

    Route::post('telegram/init',   [TelegramBotAuthController::class, 'init']);
    Route::get('telegram/poll',    [TelegramBotAuthController::class, 'poll']);
    Route::get('telegram/session', [TelegramBotAuthController::class, 'session']);
    Route::post('telegram/webhook', [TelegramBotAuthController::class, 'webhook'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
});
