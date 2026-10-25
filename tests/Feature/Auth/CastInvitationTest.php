<?php

namespace Tests\Feature\Auth;

use App\Application\CastManagement\CastInvitationService;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CastInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private object $store;
    private ?array $issuedInvitation = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->manager = User::where('email', 'nishioka@example.com')->firstOrFail();
        $this->store = DB::table('t_store')->sole();
        config(['services.line.client_id' => '123', 'services.line.client_secret' => 'secret',
            'services.line.frontend_url' => 'https://aster.example.test']);
        Http::preventStrayRequests();
    }

    private function invitation(): array
    {
        if ($this->issuedInvitation === null) {
            $data = app(CastInvitationService::class)->issue($this->store->id);
            parse_str(parse_url($data['url'], PHP_URL_QUERY), $params);
            $this->issuedInvitation = $params;
        }
        return $this->issuedInvitation;
    }

    private function start(?array $invitation = null): array
    {
        $this->get('/auth/line/redirect?'.http_build_query($invitation ?? $this->invitation()))->assertRedirect();
        return session('line_login');
    }

    private function fakeIdentity(string $sub = 'Uinvited'): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'signed-token']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => $sub, 'name' => '招待キャスト']),
        ]);
    }

    public function test_invitation_is_valid_until_fifteen_minutes_and_expires_at_exact_boundary(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25 00:00:00', 'UTC'));
        $params = $this->invitation();
        $record = DB::table('t_cast_invitations')->sole();
        $this->assertSame(hash('sha256', $params['invite']), $record->token_hash);
        $this->assertNotSame($params['invite'], $record->encrypted_token);
        $this->assertSame('2026-10-25 00:15:00', $record->expires_at);
        $this->travel(14)->minutes(); $this->travel(59)->seconds();
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($params))->assertOk();
        $this->travel(1)->seconds();
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($params))->assertUnprocessable();
        $this->actingAs($this->manager)->getJson('/api/admin/casts/invitation')->assertOk()->assertJsonPath('url', null);
    }

    public function test_reissuing_revokes_old_qr_without_rotating_store_code_or_other_stores(): void
    {
        $old = $this->invitation();
        $otherId = DB::table('t_store')->insertGetId(['ulid' => (string) Str::ulid(), 'organization_id' => $this->store->organization_id, 'name' => '他店舗']);
        $otherInvitation = app(CastInvitationService::class)->issue($otherId);
        parse_str(parse_url($otherInvitation['url'], PHP_URL_QUERY), $otherParams);
        $new = $this->actingAs($this->manager)->postJson('/api/admin/casts/invitation')->assertOk()->json();
        parse_str(parse_url($new['url'], PHP_URL_QUERY), $newParams);
        $this->assertSame($old['store'], $newParams['store']);
        $this->assertNotSame($old['invite'], $newParams['invite']);
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($old))->assertUnprocessable();
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($newParams))->assertOk();
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($otherParams))->assertOk();
        $this->assertDatabaseCount('t_cast_invitations', 2);
    }

    public function test_invitation_expired_during_line_authorization_does_not_register_user(): void
    {
        $this->invitation();
        $this->travel(14)->minutes();
        $attempt = $this->start();
        $this->travel(2)->minutes();
        $this->fakeIdentity();
        $response = $this->completeLogin($attempt);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame('invitation_expired', $params['error']);
        $this->assertDatabaseCount('mst_users', 2);
        $this->assertDatabaseCount('t_store_users', 2);
        $this->assertGuest();
    }

    public function test_regeneration_during_line_authorization_invalidates_pending_registration(): void
    {
        $attempt = $this->start();
        app(CastInvitationService::class)->issue($this->store->id);
        $this->fakeIdentity();
        $response = $this->completeLogin($attempt);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame('invalid_invitation', $params['error']);
        $this->assertDatabaseCount('mst_users', 2);
        $this->assertGuest();
    }

    public function test_cast_cannot_issue_and_reading_current_qr_does_not_rotate_it(): void
    {
        $cast = User::where('email', 'ayana@example.com')->firstOrFail();
        $this->actingAs($cast)->postJson('/api/admin/casts/invitation')->assertForbidden();
        $old = $this->invitation();
        $url = $this->actingAs($this->manager)->getJson('/api/admin/casts/invitation')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame($old, $params);
    }

    private function completeLogin(array $attempt, string $extra = '')
    {
        return $this->get('/auth/line/callback?'.http_build_query(['state' => $attempt['state'], 'code' => 'code']).$extra);
    }

    public function test_manager_gets_signed_url_for_selected_store_and_only_managers_can_issue_it(): void
    {
        $this->getJson('/api/admin/casts/invitation')->assertUnauthorized();
        $cast = User::where('email', 'ayana@example.com')->firstOrFail();
        $this->actingAs($cast)->getJson('/api/admin/casts/invitation')->assertForbidden();
        $this->actingAs($this->manager)->getJson('/api/admin/casts/invitation')->assertOk()->assertJsonPath('url', null);
        $data = $this->postJson('/api/admin/casts/invitation')->assertOk()->json();
        $this->assertSame($this->store->ulid, $data['store_code']);
        $this->assertStringStartsWith('https://aster.example.test/login?', $data['url']);
        parse_str(parse_url($data['url'], PHP_URL_QUERY), $query);
        $this->issuedInvitation = $query;
        $this->assertSame($this->store->ulid, $query['store']);
        $this->getJson('/api/cast-invitations/resolve?'.http_build_query($query))->assertOk()->assertJsonPath('store_name', $this->store->name);
    }

    public function test_line_invitation_registers_cast_without_role_and_allows_own_shifts_only(): void
    {
        $attempt = $this->start();
        $this->assertSame($this->invitation(), $attempt['invitation']);
        $this->fakeIdentity();
        $this->completeLogin($attempt)->assertRedirect('https://aster.example.test/mypage?enrollment=registered');
        $user = User::where('line_id', 'Uinvited')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_system_admin);
        $accessId = DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
        $this->assertSame(str_pad((string) $this->store->id, 2, '0', STR_PAD_LEFT)
            .str_pad((string) $accessId, 2, '0', STR_PAD_LEFT).'A'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT), $user->login_id);
        $this->assertDatabaseHas('t_store_users', [
            'store_id' => $this->store->id, 'user_id' => $user->id, 'user_type' => 2, 'role_id' => null,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->sole()->id, 'deleted_at' => null,
        ]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('active_store.id', $this->store->id)
            ->assertJsonPath('active_membership.access_role.code', 'cast')->assertJsonPath('active_membership.role', null)
            ->assertJsonPath('permissions', ['shift.submit'])->assertJsonPath('home_path', '/my_shifts');
        $this->getJson('/api/shifts/mine')->assertOk();
        $this->getJson('/api/shifts/confirmed')->assertOk();
        $request = DB::table('t_shift_requests')->insertGetId([
            'store_id' => $this->store->id, 'requested_by' => $this->manager->id,
            'period_start' => '2026-10-26', 'period_end' => '2026-10-26', 'submission_deadline' => '2099-10-23 23:59:59',
        ]);
        $this->postJson('/api/shifts', ['shift_request_id' => $request, 'days' => [
            ['work_date' => '2026-10-26', 'work' => false, 'has_reservation' => false],
        ]])->assertOk();
        $this->getJson('/api/shifts/mine')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/shifts/status')->assertForbidden();
        $this->getJson('/api/shifts/confirmed-calendar?from=2026-10-26&to=2026-10-30')->assertForbidden();
        $this->getJson('/api/admin/casts')->assertForbidden();
    }

    public function test_repeated_qr_login_does_not_duplicate_membership_or_replace_existing_role(): void
    {
        $this->fakeIdentity();
        $this->completeLogin($this->start())->assertRedirect();
        $user = User::where('line_id', 'Uinvited')->firstOrFail();
        $role = DB::table('mst_roles')->where('code', 'mama')->sole()->id;
        DB::table('t_store_users')->where('user_id', $user->id)->update(['role_id' => $role]);
        $this->completeLogin($this->start())->assertRedirect();
        $this->assertDatabaseCount('mst_users', 3);
        $this->assertDatabaseCount('t_store_users', 3);
        $this->assertDatabaseHas('t_store_users', ['user_id' => $user->id, 'role_id' => $role]);
    }

    public function test_retired_membership_cannot_be_restored_through_invitation(): void
    {
        $user = User::where('email', 'ayana@example.com')->firstOrFail();
        $user->forceFill(['line_id' => 'Uretired'])->save();
        DB::table('t_store_users')->where('user_id', $user->id)->update(['deleted_at' => now()]);
        $this->fakeIdentity('Uretired');
        $response = $this->completeLogin($this->start());
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('invitation_retired', $query['error']);
        $this->assertSame($this->invitation()['invite'], $query['invite']);
        $this->assertSoftDeleted('t_store_users', ['user_id' => $user->id]);
        $this->assertGuest();
        $this->assertDatabaseCount('mst_users', 2);
    }

    public function test_tampered_or_missing_signature_does_not_start_registration(): void
    {
        foreach ([['store' => $this->store->ulid], [...$this->invitation(), 'invite' => 'forged'], ['store' => ['bad'], 'invite' => ['bad']]] as $params) {
            $this->getJson('/api/cast-invitations/resolve?'.http_build_query($params))->assertUnprocessable();
            $this->get('/auth/line/redirect?'.http_build_query($params))->assertRedirect('https://aster.example.test/login?error=invalid_invitation');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('mst_users', 2);
    }

    public function test_deleted_store_during_authorization_rolls_back_new_user_and_membership(): void
    {
        $attempt = $this->start();
        DB::table('t_store')->where('id', $this->store->id)->update(['deleted_at' => now()]);
        $this->fakeIdentity();
        $response = $this->completeLogin($attempt);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('invalid_invitation', $query['error']);
        $this->assertDatabaseCount('mst_users', 2);
        $this->assertDatabaseCount('t_store_users', 2);
        $this->assertGuest();
    }

    public function test_cancelled_auth_preserves_invitation_without_creating_membership(): void
    {
        $attempt = $this->start();
        $response = $this->get('/auth/line/callback?'.http_build_query(['state' => $attempt['state'], 'error' => 'access_denied']));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['error' => 'cancelled', ...$this->invitation()], $query);
        Http::assertNothingSent();
        $this->assertDatabaseCount('t_store_users', 2);
    }

    public function test_callback_ignores_query_store_and_replay_cannot_add_memberships(): void
    {
        $otherCode = (string) Str::ulid();
        $other = DB::table('t_store')->insertGetId(['ulid' => $otherCode, 'organization_id' => $this->store->organization_id, 'name' => '他店舗']);
        $attempt = $this->start(); $this->fakeIdentity();
        $this->completeLogin($attempt, '&store='.$otherCode)->assertRedirect();
        $user = User::where('line_id', 'Uinvited')->firstOrFail();
        $this->assertDatabaseMissing('t_store_users', ['store_id' => $other, 'user_id' => $user->id]);
        $this->completeLogin($attempt)->assertRedirect('https://aster.example.test/login?error=invalid_state');
        $this->assertDatabaseCount('t_store_users', 3);
    }

    public function test_existing_line_user_keeps_other_store_memberships_and_system_privilege_is_not_promoted(): void
    {
        $user = User::factory()->create(['line_id' => 'Uexisting', 'user_type' => 1]);
        $other = DB::table('t_store')->insertGetId(['ulid' => (string) Str::ulid(), 'organization_id' => $this->store->organization_id, 'name' => '他店舗']);
        $access = DB::table('mst_access_roles')->where('code', 'staff')->sole()->id;
        $membership = DB::table('t_store_users')->insertGetId(['store_id' => $other, 'user_id' => $user->id, 'user_type' => 1, 'access_role_id' => $access]);
        $this->fakeIdentity('Uexisting');
        $this->completeLogin($this->start())->assertRedirect();
        $this->assertDatabaseHas('t_store_users', ['id' => $membership, 'access_role_id' => $access, 'deleted_at' => null]);
        $this->assertDatabaseHas('t_store_users', ['store_id' => $this->store->id, 'user_id' => $user->id, 'user_type' => 2, 'role_id' => null]);
        $this->assertDatabaseCount('mst_users', 3);
        $this->assertFalse($user->fresh()->is_system_admin);
    }
}
