<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_schedule_day_id')->constrained('t_shift_schedule_days');
            $table->foreignId('user_id')->constrained('mst_users');
            $table->boolean('work')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('ends_next_day')->default(false);
            $table->boolean('has_reservation')->default(false)->comment('予約・同伴あり');
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_schedule_day_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_shift_assignments');
    }
};
