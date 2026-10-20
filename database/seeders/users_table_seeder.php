<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class users_table_seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::unguarded(fn () => User::updateOrCreate(
            ['email' => 'nishioka@example.com'],
            [
                'name' => 'nishioka',
                'password' => Hash::make('pass'),
                'auth_type' => 1,
                'user_type' => 1,
                'role_type' => 1,
            ],
        ));

        User::unguarded(fn () => User::updateOrCreate(
            ['email' => 'ayana@example.com'],
            [
                'name' => 'Ayana',
                'password' => Hash::make('pass'),
                'auth_type' => 0,
                'user_type' => 2,
                'role_type' => 3,
            ],
        ));
    }
}
