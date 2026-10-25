<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.client_id' => '123', 'services.line.client_secret' => 'secret',
            'services.line.frontend_url' => 'http://localhost:5173']);
        Http::preventStrayRequests();
    }

    public function test_redirect_sets_state_nonce_and_pkce(): void
    {
        $response = $this->get('/auth/line/redirect');
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $attempt = session('line_login');
        $this->assertSame($attempt['state'], $query['state']);
        $this->assertSame($attempt['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('aggressive', $query['bot_prompt']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
    }

    public function test_callback_creates_user_and_reuses_verified_line_identity(): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'signed-token']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => 'U123', 'name' => 'テスト', 'picture' => 'https://example.com/avatar.png']),
        ]);
        for ($i = 0; $i < 2; $i++) {
            $this->withSession(['line_login' => $this->attempt()])
                ->get('/auth/line/callback?state=state&code=code')
                ->assertRedirect('http://localhost:5173/mypage');
            $this->assertAuthenticatedAs(User::where('line_id', 'U123')->firstOrFail());
        }
        $this->assertDatabaseCount('mst_users', 1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify') && $request['nonce'] === 'nonce');
    }

    public function test_invalid_state_expiry_and_replay_are_rejected(): void
    {
        $this->withSession(['line_login' => $this->attempt()])
            ->get('/auth/line/callback?state=wrong&code=code')
            ->assertRedirect('http://localhost:5173/login?error=invalid_state');
        $this->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/login?error=invalid_state');
        $this->withSession(['line_login' => array_replace($this->attempt(), ['expires_at' => now()->subMinute()->timestamp])])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/login?error=invalid_state');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_denied_consent_and_invalid_id_token_do_not_login(): void
    {
        $this->withSession(['line_login' => $this->attempt()])
            ->get('/auth/line/callback?state=state&error=access_denied')
            ->assertRedirect('http://localhost:5173/login?error=cancelled');
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'bad-token']),
            'api.line.me/oauth2/v2.1/verify' => Http::response([], 400),
        ]);
        $this->withSession(['line_login' => $this->attempt()])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/login?error=failed');
        $this->assertGuest();
        $this->assertDatabaseCount('mst_users', 0);
    }

    public function test_api_user_requires_session_and_accepts_frontend_session(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_link_requires_login_and_binds_attempt_to_current_user(): void
    {
        $this->postJson('/auth/line/link')->assertUnauthorized();
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/auth/line/link')->assertOk();
        $this->assertSame($user->id, session('line_login.link_user_id'));
        $this->assertStringStartsWith('https://access.line.me/oauth2/v2.1/authorize?', $response->json('url'));
    }

    public function test_link_updates_current_account_without_changing_name_or_creating_user(): void
    {
        $user = User::factory()->create(['name' => '店舗の名前']);
        $this->fakeLineIdentity();
        $this->actingAs($user)->withSession(['line_login' => [...$this->attempt(), 'link_user_id' => $user->id]])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/mypage?line=linked');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('U123', $user->fresh()->line_id);
        $this->assertSame('店舗の名前', $user->fresh()->name);
        $this->assertDatabaseCount('mst_users', 1);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('line_linked', true);
    }

    public function test_link_does_not_take_line_identity_from_another_account(): void
    {
        $owner = User::factory()->create(['line_id' => 'U123']);
        $user = User::factory()->create();
        $this->fakeLineIdentity();
        $this->actingAs($user)->withSession(['line_login' => [...$this->attempt(), 'link_user_id' => $user->id]])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/mypage?line=failed');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->line_id);
        $this->assertSame('U123', $owner->fresh()->line_id);
    }

    public function test_link_rejects_changed_session_and_does_not_exchange_code(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['line_login' => [...$this->attempt(), 'link_user_id' => $user->id]])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/mypage?line=session_changed');
        Http::assertNothingSent();
        $this->assertNull($user->fresh()->line_id);
        $this->assertNull($other->fresh()->line_id);
    }

    public function test_link_cancellation_and_expiry_return_to_my_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['line_login' => [...$this->attempt(), 'link_user_id' => $user->id]])
            ->get('/auth/line/callback?state=state&error=access_denied')
            ->assertRedirect('http://localhost:5173/mypage?line=cancelled');
        $this->withSession(['line_login' => [...$this->attempt(), 'link_user_id' => $user->id, 'expires_at' => now()->subMinute()->timestamp]])
            ->get('/auth/line/callback?state=state&code=code')
            ->assertRedirect('http://localhost:5173/mypage?line=invalid_state');
        Http::assertNothingSent();
        $this->assertNull($user->fresh()->line_id);
    }

    public function test_link_status_and_unconfigured_or_already_linked_accounts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('line_linked', false);
        config(['services.line.client_id' => null]);
        $this->postJson('/auth/line/link')->assertStatus(503);
        config(['services.line.client_id' => '123']);
        $user->forceFill(['line_id' => 'U123'])->save();
        $this->postJson('/auth/line/link')->assertStatus(409);
    }

    private function fakeLineIdentity(): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'signed-token']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => 'U123', 'name' => 'LINEの名前']),
        ]);
    }

    public function test_user_context_returns_configured_official_account_friend_link(): void
    {
        config(['services.line.official_account_id' => '@949bhgpp']);
        $user = User::factory()->create(['line_id' => 'Ufriend']);
        $this->actingAs($user)->getJson('/api/user')->assertOk()
            ->assertJsonPath('line_friend_url', 'https://line.me/R/ti/p/%40949bhgpp');
        config(['services.line.official_account_id' => null]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('line_friend_url', null);
    }

    private function attempt(): array
    {
        return ['state' => 'state', 'nonce' => 'nonce', 'verifier' => str_repeat('v', 64),
            'expires_at' => now()->addMinutes(10)->timestamp];
    }
}
