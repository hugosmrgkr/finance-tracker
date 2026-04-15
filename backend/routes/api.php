<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BudgetController;
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

Route::get('/budgets', [BudgetController::class, 'index']);
Route::post('/budgets', [BudgetController::class, 'store']);
Route::put('/budgets/{budget}', [BudgetController::class, 'update']);
Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy']);

Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);
Route::get('/analytics/by-category', [AnalyticsController::class, 'byCategory']);
Route::get('/analytics/monthly', [AnalyticsController::class, 'monthly']);
Route::get('/analytics/daily', [AnalyticsController::class, 'daily']);
Route::get('/analytics/monthly-growth', [AnalyticsController::class, 'monthlyGrowth']);
Route::get('/analytics/yearly-growth', [AnalyticsController::class, 'yearlyGrowth']);
Route::get('/analytics/kpis', [AnalyticsController::class, 'kpis']);
Route::get('/analytics/insights', [AnalyticsController::class, 'insights']);
Route::get('/analytics/top-categories', [AnalyticsController::class, 'topCategories']);
Route::get('/analytics/budget-vs-actual', [AnalyticsController::class, 'budgetVsActual']);
