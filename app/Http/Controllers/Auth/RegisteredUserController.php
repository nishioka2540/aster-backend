<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\RegisterUserUseCase;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules;

/**
 * ユーザー登録の入力を検証し、ユーザー生成・保存・ログインをUsecaseへ委譲する。
 */
class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, RegisterUserUseCase $useCase): Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $useCase->execute($request->only('name', 'email', 'password'));

        return response()->noContent();
    }
}
