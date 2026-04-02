<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices compuestos para optimizar las queries más frecuentes del dashboard.
     * Reduce el tiempo de respuesta de 100+ queries que filtran por estado + fecha.
     */
    public function up(): void
    {
        // Ventas en ruta: filtradas por estado + fecha_venta en dashboard y reportes
        Schema::table('viaje_ventas', function (Blueprint $table) {
            $table->index(['estado', 'fecha_venta'], 'idx_viaje_ventas_estado_fecha');
        });

        // Ventas de bodega: filtradas por estado + created_at
        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['estado', 'created_at'], 'idx_ventas_estado_created');
        });

        // Compras: filtradas por created_at para dashboard
        Schema::table('compras', function (Blueprint $table) {
            $table->index('created_at', 'idx_compras_created');
        });

        // Gastos de camión: filtrados por estado + fecha
        Schema::table('camion_gastos', function (Blueprint $table) {
            $table->index(['estado', 'fecha'], 'idx_camion_gastos_estado_fecha');
        });

        // Gastos de bodega: filtrados por estado + fecha + categoria_contable
        Schema::table('bodega_gastos', function (Blueprint $table) {
            $table->index(['estado', 'fecha', 'categoria_contable'], 'idx_bodega_gastos_estado_fecha_cat');
        });

        // Mermas de viaje: filtradas por created_at
        Schema::table('viaje_mermas', function (Blueprint $table) {
            $table->index('created_at', 'idx_viaje_mermas_created');
        });

        // Movimientos contables del chofer: filtrados por tipo + created_at
        Schema::table('chofer_cuenta_movimientos', function (Blueprint $table) {
            $table->index(['tipo', 'created_at'], 'idx_chofer_mov_tipo_created');
        });

        // Viajes: filtrados por estado + fecha_salida (para comando de reconstrucción)
        Schema::table('viajes', function (Blueprint $table) {
            $table->index(['estado', 'fecha_salida'], 'idx_viajes_estado_fecha_salida');
        });
    }

    public function down(): void
    {
        Schema::table('viaje_ventas', fn (Blueprint $table) => $table->dropIndex('idx_viaje_ventas_estado_fecha'));
        Schema::table('ventas', fn (Blueprint $table) => $table->dropIndex('idx_ventas_estado_created'));
        Schema::table('compras', fn (Blueprint $table) => $table->dropIndex('idx_compras_created'));
        Schema::table('camion_gastos', fn (Blueprint $table) => $table->dropIndex('idx_camion_gastos_estado_fecha'));
        Schema::table('bodega_gastos', fn (Blueprint $table) => $table->dropIndex('idx_bodega_gastos_estado_fecha_cat'));
        Schema::table('viaje_mermas', fn (Blueprint $table) => $table->dropIndex('idx_viaje_mermas_created'));
        Schema::table('chofer_cuenta_movimientos', fn (Blueprint $table) => $table->dropIndex('idx_chofer_mov_tipo_created'));
        Schema::table('viajes', fn (Blueprint $table) => $table->dropIndex('idx_viajes_estado_fecha_salida'));
    }
};
