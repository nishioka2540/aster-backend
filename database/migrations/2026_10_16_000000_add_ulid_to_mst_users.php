<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->unique();
        });

        // Include soft-deleted users so every existing row has a public identifier.
        DB::table('mst_users')->whereNull('ulid')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('mst_users')->where('id', $user->id)->update(['ulid' => (string) Str::ulid()]);
            }
        });

        Schema::table('mst_users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mst_users', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropColumn('ulid');
        });
    }
};
