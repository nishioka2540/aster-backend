<?php

namespace App\Application\CastManagement;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

final class CastInvitationService
{
    public function issue(int $storeId): array
    {
        return DB::transaction(function () use ($storeId) {
            $store = $this->storeQuery()->where('store.id', $storeId)->lockForUpdate()->first(['store.id', 'store.ulid', 'store.name']);
            abort_unless($store, 404);
            $token = Str::random(64);
            $issued = CarbonImmutable::now('UTC');
            DB::table('t_cast_invitations')->updateOrInsert(['store_id' => $storeId], [
                'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token),
                'issued_at' => $issued->format('Y-m-d H:i:s'), 'expires_at' => $issued->addMinutes(15)->format('Y-m-d H:i:s'),
            ]);
            return $this->current($storeId);
        });
    }

    private function storeQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('t_store as store')->join('t_organizations as organization', 'organization.id', '=', 'store.organization_id')
            ->whereNull('store.deleted_at')->whereNull('organization.deleted_at');
    }

    public function current(int $storeId): array
    {
        $store = $this->storeQuery()->where('store.id', $storeId)->first(['store.id', 'store.ulid', 'store.name']);
        abort_unless($store, 404);
        $invitation = DB::table('t_cast_invitations')->where('store_id', $storeId)->first();
        $expires = $invitation ? CarbonImmutable::parse($invitation->expires_at, 'UTC') : null;
        $valid = $expires && $expires->isFuture();
        $url = null;
        if ($valid) {
            $params = ['store' => $store->ulid, 'invite' => Crypt::decryptString($invitation->encrypted_token)];
            $url = rtrim(config('services.line.frontend_url'), '/').'/login?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return ['store_name' => $store->name, 'store_code' => $store->ulid, 'url' => $url,
            'issued_at' => $invitation ? CarbonImmutable::parse($invitation->issued_at, 'UTC')->toIso8601String() : null,
            'expires_at' => $expires?->toIso8601String(), 'server_now' => CarbonImmutable::now('UTC')->toIso8601String()];
    }

    public function resolve(mixed $storeCode, mixed $signature, bool $lock = false): object
    {
        if (! is_string($storeCode) || ! preg_match('/^[0-9A-Z]{26}$/D', $storeCode)
            || ! is_string($signature) || ! preg_match('/^[a-zA-Z0-9]{64}$/D', $signature)) {
            throw new CastInvitationException('invalid_invitation');
        }
        $query = $this->storeQuery()->where('store.ulid', $storeCode);
        if ($lock) $query->lockForUpdate();
        $store = $query->first(['store.id', 'store.ulid', 'store.name']);
        if (! $store) throw new CastInvitationException('invalid_invitation');
        $invitationQuery = DB::table('t_cast_invitations')->where('store_id', $store->id);
        if ($lock) $invitationQuery->lockForUpdate();
        $invitation = $invitationQuery->first();
        if (! $invitation || ! hash_equals($invitation->token_hash, hash('sha256', $signature))) throw new CastInvitationException('invalid_invitation');
        if (CarbonImmutable::parse($invitation->expires_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) throw new CastInvitationException('invitation_expired');
        $store->invitation_expires_at = $invitation->expires_at;
        return $store;
    }

    /** LINE本人確認と同一トランザクション内で呼び出す。 */
    public function enroll(User $user, array $invitation): int
    {
        $store = $this->resolve($invitation['store'], $invitation['invite'], true);
        $membership = DB::table('t_store_users')->where('store_id', $store->id)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($membership) {
            // 既存の役職は上書きせず、退職済み所属を招待から復帰させない。
            if ($membership->deleted_at !== null) throw new CastInvitationException('invitation_retired');
            app(\App\Services\LoginIdGenerator::class)->assign($user, (int) $store->id, (int) $membership->access_role_id);
            return (int) $store->id;
        }
        $access = DB::table('mst_access_roles')->where('code', 'cast')->sole();
        DB::table('t_store_users')->insert([
            'store_id' => $store->id, 'user_id' => $user->id, 'user_type' => $access->user_type,
            'access_role_id' => $access->id, 'role_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\LoginIdGenerator::class)->assign($user, (int) $store->id, (int) $access->id);
        return (int) $store->id;
    }
}
