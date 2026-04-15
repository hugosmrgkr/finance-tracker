<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;

Route::get('/health', fn() => response()->json(['status' => 'ok']));

Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/categories', [CategoryController::class, 'store']);
Route::put('/categories/{category}', [CategoryController::class, 'update']);
Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

Route::get('/transactions', [TransactionController::class, 'index']);
Route::post('/transactions', [TransactionController::class, 'store']);
Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);

Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);
Route::get('/analytics/by-category', [AnalyticsController::class, 'byCategory']);
Route::get('/analytics/monthly', [AnalyticsController::class, 'monthly']);
