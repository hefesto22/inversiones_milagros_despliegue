<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices compuestos para optimizar las queries más frecuentes
 * del área de compras y lotes.
 *
 * Identificados en auditoría de código: estas queries se ejecutan
 * en cada carga de página de los Resources de Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lotes: usado por obtenerOCrearLoteUnico() y filtros de listado
        Schema::table('lotes', function (Blueprint $table) {
            $table->index(['producto_id', 'bodega_id', 'estado'], 'idx_lotes_producto_bodega_estado');
            $table->index(['bodega_id', 'estado'], 'idx_lotes_bodega_estado');
        });

        // Compras: filtros de listado por bodega y estado
        Schema::table('compras', function (Blueprint $table) {
            $table->index(['bodega_id', 'estado'], 'idx_compras_bodega_estado');
        });

        // Mermas: consultas de mermas recientes por lote (eliminar_merma action)
        Schema::table('mermas', function (Blueprint $table) {
            $table->index(['lote_id', 'created_at'], 'idx_mermas_lote_fecha');
            $table->index(['bodega_id', 'created_at'], 'idx_mermas_bodega_fecha');
        });

        // Compra detalles: relación hasMany desde Compra
        Schema::table('compra_detalles', function (Blueprint $table) {
            $table->index(['compra_id'], 'idx_compra_detalles_compra');
        });

        // Historial compras lote: consultas de historial por lote
        Schema::table('historial_compras_lote', function (Blueprint $table) {
            $table->index(['lote_id', 'created_at'], 'idx_historial_lote_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex('idx_lotes_producto_bodega_estado');
            $table->dropIndex('idx_lotes_bodega_estado');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->dropIndex('idx_compras_bodega_estado');
        });

        Schema::table('mermas', function (Blueprint $table) {
            $table->dropIndex('idx_mermas_lote_fecha');
            $table->dropIndex('idx_mermas_bodega_fecha');
        });

        Schema::table('compra_detalles', function (Blueprint $table) {
            $table->dropIndex('idx_compra_detalles_compra');
        });

        Schema::table('historial_compras_lote', function (Blueprint $table) {
            $table->dropIndex('idx_historial_lote_fecha');
        });
    }
};
