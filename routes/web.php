<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LineLoginController;
use App\Http\Controllers\ShiftRequestController;

Route::post('/api/shifts/draft', [\App\Http\Controllers\ShiftDraftController::class, 'store'])->middleware(['auth', 'throttle:30,1']);
Route::get('/api/shifts/mine', [\App\Http\Controllers\ShiftDraftController::class, 'index'])->middleware(['auth', 'throttle:60,1']);

Route::get('/api/admin/shift-requests/{requestId}/casts/{castId}', [\App\Http\Controllers\AdminCastShiftController::class, 'show'])
    ->whereNumber('requestId')->whereUlid('castId')->middleware(['auth', 'throttle:60,1']);
Route::put('/api/admin/shift-requests/{requestId}/casts/{castId}', [\App\Http\Controllers\AdminCastShiftController::class, 'update'])
    ->whereNumber('requestId')->whereUlid('castId')->middleware(['auth', 'throttle:30,1']);

Route::post('/api/shifts', \App\Http\Controllers\SubmitShiftController::class)
    ->middleware(['auth', 'throttle:30,1']);

Route::get('/api/shifts/status', \App\Http\Controllers\GetShiftStatusController::class)
    ->middleware(['auth', 'throttle:60,1']);

// webミドルウェアでセッションとCSRFを検証するSPA向けエンドポイント。
Route::post('/api/shift-requests', ShiftRequestController::class)
    ->middleware(['auth', 'throttle:30,1']);

Route::get('/auth/line/redirect', [LineLoginController::class, 'redirect'])->middleware('throttle:30,1');
Route::get('/auth/line/callback', [LineLoginController::class, 'callback'])->middleware('throttle:30,1');

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

require __DIR__.'/auth.php';

Route::get('/api/shift-requests/accepting', \App\Http\Controllers\GetAcceptingShiftRequestsController::class)
    ->middleware(['auth', 'throttle:60,1']);

Route::get('/api/shift-requests/defaults', \App\Http\Controllers\ShiftRequestDefaultsController::class)->middleware(['auth', 'throttle:60,1']);

Route::middleware(['auth', 'throttle:60,1'])->prefix('/api/admin/shift-requests/{requestId}/schedule')->whereNumber('requestId')->group(function () {
    Route::get('/', [\App\Http\Controllers\ShiftScheduleController::class, 'show']);
    Route::get('/days/{date}', [\App\Http\Controllers\ShiftScheduleController::class, 'day']);
    Route::put('/days/{date}', [\App\Http\Controllers\ShiftScheduleController::class, 'decide']);
    Route::post('/confirm', [\App\Http\Controllers\ShiftScheduleController::class, 'confirm']);
});

Route::get('/api/shifts/confirmed', [\App\Http\Controllers\ShiftScheduleController::class, 'mine'])->middleware(['auth', 'throttle:60,1']);

Route::put('/api/shift-requests/{requestId}', [ShiftRequestController::class, 'update'])
    ->whereNumber('requestId')->middleware(['auth', 'throttle:30,1']);
