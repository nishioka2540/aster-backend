<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\VerifyEmailUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

/**
 * 確認リンクが検証されたリクエストを受け取り、確認済みの登録をUsecaseへ委譲する。
 */
class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request, VerifyEmailUseCase $useCase): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(
                config('app.frontend_url').'/dashboard?verified=1'
            );
        }

        $useCase->execute($request->user());

        return redirect()->intended(
            config('app.frontend_url').'/dashboard?verified=1'
        );
    }
}
