<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\ResetPasswordUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;

/**
 * パスワード再設定の入力を検証し、トークン検証と更新をUsecaseへ委譲する。
 */
class NewPasswordController extends Controller
{
    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, ResetPasswordUseCase $useCase): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = $useCase->execute($request->only('email', 'password', 'password_confirmation', 'token'));

        return response()->json(['status' => __($status)]);
    }
}
