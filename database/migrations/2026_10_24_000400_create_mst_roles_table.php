<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('t_store');
            $table->foreignId('access_role_id')->constrained('mst_access_roles');
            $table->string('code', 50);
            $table->string('name', 100)->comment('ママ・店長など、店舗ごとの役職名');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'access_role_id', 'code']);
            $table->unique(['id', 'store_id', 'access_role_id'], 'roles_id_store_access_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_roles');
    }
};
