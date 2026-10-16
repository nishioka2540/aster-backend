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
        Schema::create('t_shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('mst_users');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('status')->default(0)->index()->comment('0: 未確定, 1: 確定済み');
            $table->timestamp('submission_deadline');
            $table->text('notice')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_shift_requests');
    }
};
