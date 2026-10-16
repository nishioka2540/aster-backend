<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('line_id')->nullable()->unique();
            $table->text('line_picture_url')->nullable();
        });
    }

    public function down(): void
    {
        // Keep nullable credentials: LINE users may not have email/password.
        Schema::table('mst_users', function (Blueprint $table) {
            $table->dropUnique(['line_id']);
            $table->dropColumn(['line_id', 'line_picture_url']);
        });
    }
};
