<?php

namespace App\Http\Controllers\Auth;

use App\Application\Auth\LineLoginUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * LINEログインを開始し、コールバックのstate・有効期限を検証する。
 * トークン検証・ユーザー保存・ログインをUsecaseへ委譲し、セッションを更新する。
 */
class LineLoginController extends Controller
{
    public function link(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_if(! config('services.line.client_id') || ! config('services.line.client_secret'), 503,
            'LINE連携の設定がまだ完了していません。管理者にお問い合わせください。');
        abort_if((bool) $request->user()->line_id, 409, 'すでにLINE連携済みです。');
        $redirect = $this->redirect($request);
        $attempt = $request->session()->get('line_login');
        $attempt['link_user_id'] = $request->user()->getAuthIdentifier();
        $request->session()->put('line_login', $attempt);

        return response()->json(['url' => $redirect->getTargetUrl()]);
    }

    /**
     * 認証に必要な一時情報をセッションに保存し、LINEの認証画面へ移動する。
     */
    public function redirect(Request $request): RedirectResponse
    {
        $invitation = null;
        if ($request->isMethod('GET') && ($request->has('store') || $request->has('invite'))) {
            try {
                app(\App\Application\CastManagement\CastInvitationService::class)->resolve($request->query('store'), $request->query('invite'));
                $invitation = ['store' => $request->query('store'), 'invite' => $request->query('invite')];
            } catch (\App\Application\CastManagement\CastInvitationException $exception) {
                return $this->failure($exception->getMessage());
            }
        }
        if (! config('services.line.client_id') || ! config('services.line.client_secret')) {
            return $this->failure('not_configured', false, $invitation);
        }

        // stateは認証リクエストとコールバックの照合、nonceはIDトークンの照合に使う。
        // verifierはPKCE用の秘密値として保持し、認可コードの交換時に送信する。
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('line_login', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'expires_at' => now()->addMinutes(10)->timestamp,
            'invitation' => $invitation,
        ]);

        return redirect()->away('https://access.line.me/oauth2/v2.1/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.line.client_id'),
            'redirect_uri' => config('services.line.redirect_uri'),
            'state' => $state,
            'scope' => 'openid profile',
            'bot_prompt' => 'aggressive',
            'nonce' => $nonce,
            // verifier自体は渡さず、SHA-256をBase64URL形式にした値をLINEへ渡す。
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * LINEからの応答を検証し、対応するユーザーでログインする。
     */
    public function callback(Request $request, LineLoginUseCase $useCase): RedirectResponse
    {
        // pullで一時情報を取り出すと同時に削除し、同じ認証試行の再利用を防ぐ。
        $attempt = $request->session()->pull('line_login');
        $linking = is_array($attempt) && isset($attempt['link_user_id']);
        $invitation = is_array($attempt) && ! $linking ? ($attempt['invitation'] ?? null) : null;
        $state = $request->query('state');
        // セッションに紐づくstateと有効期限を確認してから、LINEの応答を処理する。
        if (! is_array($attempt) || ! is_string($state)
            || ! hash_equals($attempt['state'], $state)
            || $attempt['expires_at'] < now()->timestamp) {
            return $this->failure('invalid_state', $linking, $invitation);
        }
        if ($linking && (! $request->user() || (string) $request->user()->getAuthIdentifier() !== (string) $attempt['link_user_id'])) {
            return $this->failure('session_changed', true);
        }
        if ($request->query('error')) {
            return $this->failure('cancelled', $linking, $invitation);
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $this->failure('failed', $linking, $invitation);
        }

        try {
            $user = $useCase->execute($code, $attempt['verifier'], $attempt['nonce'], $linking ? $request->user() : null, $invitation);
        } catch (\App\Application\CastManagement\CastInvitationException $exception) {
            return $this->failure($exception->getMessage(), false, $invitation);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            if ($invitation) return $this->failure('failed', false, $invitation);
            if (! $linking) {
                throw $exception;
            }
            return $this->failure('already_linked', true);
        }
        if (! $user) {
            return $this->failure('failed', $linking, $invitation);
        }
        if ($linking) {
            \Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
        }
        // ログイン前のセッションIDを更新し、セッション固定攻撃を防ぐ。
        if (! $linking) $request->session()->forget('active_store_id');
        if ($invitation) {
            $request->session()->put('active_store_id', (int) \Illuminate\Support\Facades\DB::table('t_store')->where('ulid', $invitation['store'])->value('id'));
        }
        $request->session()->regenerate();

        return redirect()->away(rtrim(config('services.line.frontend_url'), '/').($linking ? '/mypage?line=linked' : ($invitation ? '/mypage?enrollment=registered' : $user->homePath())));
    }

    /**
     * フロントエンドのログイン画面へ、表示用のエラー理由を渡す。
     */
    private function failure(string $reason, bool $linking = false, ?array $invitation = null): RedirectResponse
    {
        $params = $linking ? ['line' => $reason] : ['error' => $reason, ...($invitation ?? [])];
        return redirect()->away(rtrim(config('services.line.frontend_url'), '/').($linking ? '/mypage?' : '/login?').http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
