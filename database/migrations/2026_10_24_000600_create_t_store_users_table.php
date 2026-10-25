<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_store_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('t_store');
            $table->foreignId('user_id')->constrained('mst_users');
            $table->unsignedTinyInteger('user_type')->comment('1:スタッフ 2:キャスト');
            $table->unsignedBigInteger('access_role_id');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // 退店後は同じ所属レコードを復元し、重複所属を防ぐ。
            $table->unique(['store_id', 'user_id']);
            $table->foreign(['access_role_id', 'user_type'], 'store_users_access_role_fk')
                ->references(['id', 'user_type'])->on('mst_access_roles');
            // 別店舗や別区分の役職を割り当てない。
            $table->foreign(['role_id', 'store_id', 'access_role_id'], 'store_users_role_fk')
                ->references(['id', 'store_id', 'access_role_id'])->on('mst_roles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_store_users');
    }
};
