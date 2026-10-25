<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\AuthenticateSessionUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ログインIDによる認証とログアウトを受け付ける。
 * 入力・試行制限の検証後にUsecaseを呼び出し、HTTPセッションを更新する。
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, AuthenticateSessionUseCase $useCase): Response
    {
        $request->ensureIsNotRateLimited();
        $useCase->login($request->only('login_id', 'password'), $request->boolean('remember'), $request->throttleKey());

        $request->session()->regenerate();

        return response()->noContent();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, AuthenticateSessionUseCase $useCase): Response
    {
        $useCase->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
