<?php

namespace App\Application\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/** LINEの本人情報を検証し、ユーザーを生成・更新してログインする。 */
final class LineLoginUseCase
{
    public function execute(string $code, string $verifier, string $nonce, ?User $linkUser = null, ?array $invitation = null): ?User
    {
        try {
            // 認可コードをトークンに交換する。PKCEのverifierもここで照合される。
            $tokens = Http::asForm()->timeout(15)->post('https://api.line.me/oauth2/v2.1/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.line.redirect_uri'),
                'client_id' => config('services.line.client_id'),
                'client_secret' => config('services.line.client_secret'),
                'code_verifier' => $verifier,
            ]);
            if (! $tokens->successful() || ! is_string($tokens->json('id_token'))) {
                return null;
            }
            // IDトークンをLINE側で検証し、client_idとnonceが一致する本人情報を取得する。
            $verified = Http::asForm()->timeout(15)->post('https://api.line.me/oauth2/v2.1/verify', [
                'id_token' => $tokens->json('id_token'),
                'client_id' => config('services.line.client_id'),
                'nonce' => $nonce,
            ]);
            if (! $verified->successful() || ! is_string($verified->json('sub')) || $verified->json('sub') === '') {
                return null;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            return null;
        }

        if ($linkUser) {
            // 既存アカウントの氏名・権限を保持し、別アカウントからLINEを移さない。
            return \Illuminate\Support\Facades\DB::transaction(function () use ($linkUser, $verified) {
                $user = User::whereKey($linkUser->getKey())->lockForUpdate()->firstOrFail();
                if ($user->line_id || User::withTrashed()->where('line_id', $verified->json('sub'))->exists()) {
                    return null;
                }
                $user->fill(['line_id' => $verified->json('sub'), 'line_picture_url' => $verified->json('picture')]);
                $user->save();

                return $user;
            });
        }

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($verified, $invitation) {
            // LINE本人情報だけで照合し、削除済みアカウントは復帰させない。
            $user = User::withTrashed()->where('line_id', $verified->json('sub'))->lockForUpdate()->first();
            if ($user?->trashed()) return null;
            $user ??= new User(['line_id' => $verified->json('sub'), 'name' => $verified->json('name') ?: 'LINEユーザー']);
            $user->fill(['name' => $verified->json('name') ?: $user->name, 'line_picture_url' => $verified->json('picture')]);
            $user->save();
            if ($invitation) app(\App\Application\CastManagement\CastInvitationService::class)->enroll($user, $invitation);
            return $user;
        });
        if (! $user) return null;
        Auth::guard('web')->login($user);

        return $user;
    }
}
