<?php

namespace Database\Seeders;

use App\Models\User;
use Enum\AuthType;
use Enum\CastRole;
use Enum\StaffRole;
use Enum\UserType;
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
                'auth_type' => AuthType::Administrator->value,
                'user_type' => UserType::Staff->value,
                'role_type' => StaffRole::StoreManager->value,
            ],
        ));

        User::unguarded(fn () => User::updateOrCreate(
            ['email' => 'ayana@example.com'],
            [
                'name' => 'Ayana',
                'password' => Hash::make('pass'),
                'auth_type' => AuthType::Standard->value,
                'user_type' => UserType::Cast->value,
                'role_type' => CastRole::Other->value,
            ],
        ));
    }
}
