<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
            ],
        ));

        User::unguarded(fn () => User::updateOrCreate(
            ['email' => 'ayana@example.com'],
            [
                'name' => 'Ayana',
                'password' => Hash::make('pass'),
            ],
        ));
    }
}
