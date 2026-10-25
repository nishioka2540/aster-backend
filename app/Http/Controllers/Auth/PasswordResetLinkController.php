<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\SendPasswordResetLinkUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * メールアドレスを検証し、パスワード再設定リンクの送信をUsecaseへ委譲する。
 */
class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, SendPasswordResetLinkUseCase $useCase): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = $useCase->execute($request->input('email'));

        return response()->json(['status' => __($status)]);
    }
}
