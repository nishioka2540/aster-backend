<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * LINEログインの認証開始とコールバックの検証を行い、ユーザーのログインを処理する。
 */
class LineLoginController extends Controller
{
    /**
     * 認証に必要な一時情報をセッションに保存し、LINEの認証画面へ移動する。
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! config('services.line.client_id') || ! config('services.line.client_secret')) {
            return $this->failure('not_configured');
        }

        // stateは認証リクエストとコールバックの照合、nonceはIDトークンの照合に使う。
        // verifierはPKCE用の秘密値として保持し、認可コードの交換時に送信する。
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('line_login', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away('https://access.line.me/oauth2/v2.1/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.line.client_id'),
            'redirect_uri' => config('services.line.redirect_uri'),
            'state' => $state,
            'scope' => 'openid profile',
            'nonce' => $nonce,
            // verifier自体は渡さず、SHA-256をBase64URL形式にした値をLINEへ渡す。
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * LINEからの応答を検証し、対応するユーザーでログインする。
     */
    public function callback(Request $request): RedirectResponse
    {
        // pullで一時情報を取り出すと同時に削除し、同じ認証試行の再利用を防ぐ。
        $attempt = $request->session()->pull('line_login');
        $state = $request->query('state');
        // セッションに紐づくstateと有効期限を確認してから、LINEの応答を処理する。
        if (! is_array($attempt) || ! is_string($state)
            || ! hash_equals($attempt['state'], $state)
            || $attempt['expires_at'] < now()->timestamp) {
            return $this->failure('invalid_state');
        }
        if ($request->query('error')) {
            return $this->failure('cancelled');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $this->failure('failed');
        }

        try {
            // 認可コードをトークンに交換する。PKCEのverifierもここで照合される。
            $tokens = Http::asForm()->timeout(15)->post('https://api.line.me/oauth2/v2.1/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.line.redirect_uri'),
                'client_id' => config('services.line.client_id'),
                'client_secret' => config('services.line.client_secret'),
                'code_verifier' => $attempt['verifier'],
            ]);
            if (! $tokens->successful() || ! is_string($tokens->json('id_token'))) {
                return $this->failure('failed');
            }
            // IDトークンをLINE側で検証し、client_idとnonceが一致する本人情報を取得する。
            $verified = Http::asForm()->timeout(15)->post('https://api.line.me/oauth2/v2.1/verify', [
                'id_token' => $tokens->json('id_token'),
                'client_id' => config('services.line.client_id'),
                'nonce' => $attempt['nonce'],
            ]);
            if (! $verified->successful() || ! is_string($verified->json('sub')) || $verified->json('sub') === '') {
                return $this->failure('failed');
            }
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            return $this->failure('failed');
        }

        // 検証済みのLINEユーザーID（sub）だけで照合し、表示名やメールでは紐づけない。
        $user = User::firstOrCreate(['line_id' => $verified->json('sub')], [
            'name' => $verified->json('name') ?: 'LINEユーザー',
        ]);
        // 再ログイン時にも、LINE側の最新の表示名とプロフィール画像を反映する。
        $user->update([
            'name' => $verified->json('name') ?: $user->name,
            'line_picture_url' => $verified->json('picture'),
        ]);
        Auth::guard('web')->login($user);
        // ログイン前のセッションIDを更新し、セッション固定攻撃を防ぐ。
        $request->session()->regenerate();

        return redirect()->away(rtrim(config('services.line.frontend_url'), '/').$user->homePath());
    }

    /**
     * フロントエンドのログイン画面へ、表示用のエラー理由を渡す。
     */
    private function failure(string $reason): RedirectResponse
    {
        return redirect()->away(rtrim(config('services.line.frontend_url'), '/').'/login?error='.$reason);
    }
}
