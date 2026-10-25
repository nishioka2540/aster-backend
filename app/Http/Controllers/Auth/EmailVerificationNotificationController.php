<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\SendEmailVerificationUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * メール確認通知の再送を受け付け、送信をUsecaseへ委譲する。
 */
class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request, SendEmailVerificationUseCase $useCase): JsonResponse|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/dashboard');
        }

        $useCase->execute($request->user());

        return response()->json(['status' => 'verification-link-sent']);
    }
}
