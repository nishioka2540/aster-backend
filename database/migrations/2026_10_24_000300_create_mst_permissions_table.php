<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
        });

        DB::table('mst_permissions')->insert([
            ['code' => 'shift_calendar.view', 'name' => '店舗全体の出勤カレンダー閲覧', 'description' => '閲覧のみ。編集・承認の権限は含まない。'],
            ['code' => 'shift.manage', 'name' => 'シフト管理', 'description' => '提出依頼・提出状況・代理編集・編成・確定。'],
            ['code' => 'shift.submit', 'name' => '自分のシフト提出・確認', 'description' => '自分のシフト提出・下書き・確定シフト確認。'],
            ['code' => 'cast.manage', 'name' => 'キャスト管理', 'description' => '所属店舗のキャスト一覧・退職・在籍復帰。'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_permissions');
    }
};
