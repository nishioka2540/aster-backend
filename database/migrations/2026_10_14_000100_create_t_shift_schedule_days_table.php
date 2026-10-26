<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_shift_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_request_id')->constrained('t_shift_requests');
            $table->date('work_date');
            $table->unsignedSmallInteger('required_count')->default(0)->comment('必要出勤人数');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=下書き、1=決定済み、2=確定済み');
            $table->foreignId('decided_by')->nullable()->constrained('mst_users');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('mst_users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_request_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_shift_schedule_days');
    }
};
