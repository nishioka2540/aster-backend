<?php

namespace App\Application\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/** ユーザーモデルを生成・保存し、登録イベントとログインを実行する。 */
final class RegisterUserUseCase
{
    public function execute(array $data): void
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->save();

        event(new Registered($user));

        Auth::login($user);

    }
}
