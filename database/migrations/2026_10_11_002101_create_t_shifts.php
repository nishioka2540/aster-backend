<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('t_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_request_id')->constrained('t_shift_requests');
            $table->foreignId('user_id')->constrained('mst_users');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_request_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_shifts');
    }
};
