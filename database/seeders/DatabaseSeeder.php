<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // 店舗・ユーザー・所属の順に作成し、所属作成時にログインIDを発行する。

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(NishiyamaStoreSeeder::class);
        $this->call(users_table_seeder::class);
        $this->call(NishiyamaStoreUserSeeder::class);
    }
}
