<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->foreignId('categoria_origen_id')
                ->nullable()
                ->after('activo')
                ->constrained('categorias')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropForeign(['categoria_origen_id']);
            $table->dropColumn('categoria_origen_id');
        });
    }
};