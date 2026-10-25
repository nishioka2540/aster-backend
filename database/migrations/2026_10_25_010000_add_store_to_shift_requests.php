<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_shift_requests', function (Blueprint $table) {
            // 旧データは所属店舗を推測せず、明示的に移行するまで非公開にする。
            $table->foreignId('store_id')->nullable()->constrained('t_store');
        });
    }

    public function down(): void
    {
        Schema::table('t_shift_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
