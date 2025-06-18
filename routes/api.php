<?php

use Illuminate\Http\Request;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 1. Configurer le webhook - routes/api.php
Route::post('/telegram/webhook', [App\Http\Controllers\TelegramBotController::class, 'handle']);
Route::post('/btcpay', [App\Http\Controllers\TelegramBotController::class, 'handleBtcpay']);
Route::get('/telegram/set-webhook', [App\Http\Controllers\TelegramBotController::class, 'setWebhook']);

