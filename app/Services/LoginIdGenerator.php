<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use OverflowException;

class LoginIdGenerator
{
    public function next(int $userType): string
    {
        $prefix = match ($userType) {
            User::TYPE_STAFF => '0101',
            User::TYPE_CAST => '0102',
            default => throw new \InvalidArgumentException('Unknown user type.'),
        };

        return DB::transaction(function () use ($prefix) {
            DB::table('login_id_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);
            $sequence = DB::table('login_id_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            $number = $sequence->last_number + 1;
            if ($number > 26 * 9999) {
                throw new OverflowException('Login ID sequence exhausted.');
            }
            DB::table('login_id_sequences')->where('prefix', $prefix)->update(['last_number' => $number]);

            return $prefix.chr(65 + intdiv($number - 1, 9999)).sprintf('%04d', ($number - 1) % 9999 + 1);
        }, 5);
    }
}
