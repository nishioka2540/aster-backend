<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_cast_invitations', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained('t_store');
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_cast_invitations');
    }
};
