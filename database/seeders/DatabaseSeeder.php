<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // ログインIDの自動採番に必要なモデルイベントを有効にして実行する。

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(users_table_seeder::class);
    }
}
