<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->decimal('cantidad_de_bodega', 14, 3)->default(0)
                ->after('costo_bodega_original')
                ->comment('Unidades que vinieron de bodega');
            $table->decimal('cantidad_de_lote', 14, 3)->default(0)
                ->after('cantidad_de_bodega')
                ->comment('Unidades que vinieron del reempaque automático de lotes');
            $table->decimal('costo_unitario_lote', 12, 4)->default(0)
                ->after('cantidad_de_lote')
                ->comment('Costo unitario de las unidades que vinieron del lote/reempaque');
        });

        // Poblar datos existentes basándose en reempaque_id y ReempaqueProducto
        DB::statement("
            UPDATE viaje_cargas vc
            LEFT JOIN reempaque_productos rp ON rp.reempaque_id = vc.reempaque_id AND rp.producto_id = vc.producto_id
            SET
                vc.cantidad_de_lote = COALESCE(rp.cantidad, 0),
                vc.cantidad_de_bodega = vc.cantidad - COALESCE(rp.cantidad, 0),
                vc.costo_unitario_lote = COALESCE(rp.costo_unitario, 0)
            WHERE vc.reempaque_id IS NOT NULL
        ");

        // Para cargas sin reempaque, todo viene de bodega
        DB::statement("
            UPDATE viaje_cargas
            SET cantidad_de_bodega = cantidad, cantidad_de_lote = 0, costo_unitario_lote = 0
            WHERE reempaque_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->dropColumn(['cantidad_de_bodega', 'cantidad_de_lote', 'costo_unitario_lote']);
        });
    }
};
