<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_access_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('user_type')->comment('1:スタッフ 2:キャスト');
            $table->unique(['id', 'user_type'], 'access_roles_id_type_unique');
        });

        DB::table('mst_access_roles')->insert([
            ['code' => 'cast', 'name' => 'キャスト', 'user_type' => 2],
            ['code' => 'staff', 'name' => 'スタッフ', 'user_type' => 1],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_access_roles');
    }
};
