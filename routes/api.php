<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return [...$request->user()->toArray(), 'home_path' => $request->user()->auth_type === 1 ? '/request_shift' : app(\App\Application\Shift\CastHomePath::class)->forUser((int) $request->user()->getAuthIdentifier())];
});
