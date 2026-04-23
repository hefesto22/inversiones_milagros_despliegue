<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reempaque_productos', function (Blueprint $table) {
            if (!Schema::hasColumn('reempaque_productos', 'costo_promedio_anterior')) {
                $table->decimal('costo_promedio_anterior', 10, 4)
                    ->nullable()
                    ->after('costo_total')
                    ->comment('Costo promedio de bodega ANTES de agregar este producto');
            }

            if (!Schema::hasColumn('reempaque_productos', 'stock_anterior')) {
                $table->decimal('stock_anterior', 10, 3)
                    ->nullable()
                    ->after('costo_promedio_anterior')
                    ->comment('Stock de bodega ANTES de agregar este producto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reempaque_productos', function (Blueprint $table) {
            $columnas = array_filter(
                ['costo_promedio_anterior', 'stock_anterior'],
                fn ($col) => Schema::hasColumn('reempaque_productos', $col)
            );

            if (!empty($columnas)) {
                $table->dropColumn($columnas);
            }
        });
    }
};
