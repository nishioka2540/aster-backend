<?php

namespace App\Application\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/** パスワード再設定リンクを送信し、送信結果を返す。 */
final class SendPasswordResetLinkUseCase
{
    public function execute(string $email): string
    {
        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            ['email' => $email]
        );

        if ($status != Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return __($status);
    }
}
