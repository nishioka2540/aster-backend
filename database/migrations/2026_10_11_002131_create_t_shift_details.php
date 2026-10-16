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
        Schema::create('t_shift_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('t_shifts');
            $table->date('work_date');
            $table->unsignedTinyInteger('availability')->default(0);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('ends_next_day')->default(false);
            $table->boolean('has_reservation')->default(false);
            $table->boolean('has_companion')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_id', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_shift_details');
    }
};
