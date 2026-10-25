<?php

namespace App\Application\Auth;

use App\Models\User;

/** ユーザーへメールアドレス確認通知を送信する。 */
final class SendEmailVerificationUseCase
{
    public function execute(User $user): void
    {
        $user->sendEmailVerificationNotification();
    }
}
