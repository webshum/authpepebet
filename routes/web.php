<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::group(['prefix' => 'auth'], function() {
    Route::get('google', [AuthController::class, 'redirect']);
    Route::get('google/callback', [AuthController::class, 'callback']);

    Route::get('vkontakte', [AuthController::class, 'redirect']);
    Route::get('vkontakte/callback', [AuthController::class, 'callback']);
});