<?php

namespace App\Application\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

/** 認証済みユーザーをメール確認済みにし、確認イベントを発行する。 */
final class VerifyEmailUseCase
{
    public function execute(User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }
}
