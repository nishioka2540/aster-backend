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

            User::factory()->cast()->create([
                'name' => sprintf('テストキャスト%02d', $i),
                'email' => $email,
            ]);
        }
    }
}
