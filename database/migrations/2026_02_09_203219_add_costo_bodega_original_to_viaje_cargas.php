<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->decimal('costo_bodega_original', 10, 4)
                ->nullable()
                ->after('costo_unitario')
                ->comment('Costo promedio de bodega ANTES de cargar, para restaurar al eliminar');
        });
    }

    public function down(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->dropColumn('costo_bodega_original');
        });
    }
};