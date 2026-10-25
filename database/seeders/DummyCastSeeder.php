<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DummyCastSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $email = sprintf('cast%02d@example.com', $i);

            // 削除済みを含め、同じダミーアカウントは重複作成しない。
            if (User::withTrashed()->where('email', $email)->exists()) {
                continue;
            }

            $user = User::factory()->cast()->create([
                'name' => sprintf('テストキャスト%02d', $i),
                'email' => $email,
            ]);
            $storeId = \Illuminate\Support\Facades\DB::table('t_store')->where('ulid', NishiyamaStoreSeeder::STORE_ULID)->sole()->id;
            $accessId = \Illuminate\Support\Facades\DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
            \Illuminate\Support\Facades\DB::table('t_store_users')->insert([
                'store_id' => $storeId, 'user_id' => $user->id, 'user_type' => 2, 'access_role_id' => $accessId, 'role_id' => null,
            ]);
            app(\App\Services\LoginIdGenerator::class)->assign($user, (int) $storeId, (int) $accessId);
        }
    }
}
