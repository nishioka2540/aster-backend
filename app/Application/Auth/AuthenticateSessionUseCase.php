<?php

namespace App\Application\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** ログイン要求の認証・試行制限とログアウトを実行する。 */
final class AuthenticateSessionUseCase
{
    public function login(array $credentials, bool $remember, string $throttleKey): void
    {
        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages(['login_id' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        request()->session()->forget('active_store_id');
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
    }
}
