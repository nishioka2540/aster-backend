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
Route::post('/auth/line/link', [LineLoginController::class, 'link'])->middleware(['auth', 'throttle:10,1']);
Route::get('/auth/line/callback', [LineLoginController::class, 'callback'])->middleware('throttle:30,1');

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

require __DIR__.'/auth.php';
Route::post('/api/user/active-store', [\App\Http\Controllers\Auth\UserContextController::class, 'select'])->middleware(['auth', 'throttle:30,1']);
Route::get('/api/user', [\App\Http\Controllers\Auth\UserContextController::class, 'show'])->middleware('auth');
Route::get('/api/admin/casts', [\App\Http\Controllers\CastManagementController::class, 'index'])->middleware(['auth', 'throttle:60,1']);
Route::get('/api/admin/casts/invitation', [\App\Http\Controllers\CastInvitationController::class, 'show'])->middleware(['auth', 'throttle:30,1,cast-invitation']);
Route::post('/api/admin/casts/invitation', [\App\Http\Controllers\CastInvitationController::class, 'issue'])->middleware(['auth', 'throttle:10,1,cast-invitation-issue']);
Route::get('/api/cast-invitations/resolve', [\App\Http\Controllers\CastInvitationController::class, 'resolve'])->middleware('throttle:30,1,cast-invitation-public');
Route::patch('/api/admin/casts/{membershipId}/employment', [\App\Http\Controllers\CastManagementController::class, 'update'])
    ->whereNumber('membershipId')->middleware(['auth', 'throttle:30,1,cast-employment']);
Route::patch('/api/admin/casts/{membershipId}/role', [\App\Http\Controllers\CastManagementController::class, 'updateRole'])
    ->whereNumber('membershipId')->middleware(['auth', 'throttle:30,1,cast-role']);

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

Route::get('/api/shifts/confirmed-calendar', \App\Http\Controllers\ConfirmedShiftCalendarController::class)
    ->middleware(['auth', 'throttle:60,1']);

Route::put('/api/shift-requests/{requestId}', [ShiftRequestController::class, 'update'])
    ->whereNumber('requestId')->middleware(['auth', 'throttle:30,1']);

Route::post('/api/admin/shift-requests/{requestId}/remind', \App\Http\Controllers\LineReminderController::class)
    ->whereNumber('requestId')->middleware(['auth', 'throttle:6,1,line-remind']);
