<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_users', function (Blueprint $table) {
            $table->string('login_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // 発行済みIDを切り詰めず、未所属ユーザーのNULLも保持する。
    }
};
