<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migración INTEGRAL de precisión de costos.
     * 
     * Cambia TODAS las columnas de costo intermedio de decimal(X,2) a decimal(X,4)
     * para evitar pérdida de centavos en la cadena:
     * Compra → Lote → Reempaque → BodegaProducto → Camión → Viaje/Venta
     * 
     * NO se tocan columnas de venta al cliente final (se mantienen en decimal(12,2))
     */
    public function up(): void
    {
        // ========================================
        // 1. LOTES - costos internos
        // ========================================
        Schema::table('lotes', function (Blueprint $table) {
            $table->decimal('costo_por_huevo', 12, 4)->nullable()->change();
            $table->decimal('costo_total_lote', 12, 4)->nullable()->change();
            // costo_por_carton_facturado ya es decimal(12,4) ✓
            // costo_total_acumulado ya es decimal(14,2) - se mantiene (es suma de dinero exacto)
        });

        // ========================================
        // 2. REEMPAQUES - costos del proceso
        // ========================================
        Schema::table('reempaques', function (Blueprint $table) {
            $table->decimal('costo_total', 12, 4)->nullable()->change();
            $table->decimal('costo_unitario_promedio', 12, 4)->nullable()->change();
        });

        // ========================================
        // 3. REEMPAQUE_LOTES - costo parcial por lote usado
        // ========================================
        Schema::table('reempaque_lotes', function (Blueprint $table) {
            $table->decimal('costo_parcial', 12, 4)->nullable()->change();
        });

        // ========================================
        // 4. REEMPAQUE_PRODUCTOS - costos de productos generados
        // ========================================
        Schema::table('reempaque_productos', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 4)->nullable()->change();
            $table->decimal('costo_total', 12, 4)->nullable()->change();
        });

        // ========================================
        // 5. CAMION_PRODUCTO - costo promedio en camión
        // ========================================
        Schema::table('camion_producto', function (Blueprint $table) {
            $table->decimal('costo_promedio', 12, 4)->nullable()->change();
        });

        // ========================================
        // 6. RECALCULAR costos de lotes desde historial_compras_lote
        //    (fuente de verdad con los costos originales de cada compra)
        // ========================================
        DB::statement("
            UPDATE lotes l
            INNER JOIN (
                SELECT 
                    lote_id,
                    SUM(costo_compra) AS total_costo,
                    SUM(cartones_facturados) AS total_cartones_facturados,
                    SUM(cartones_regalo) AS total_cartones_regalo,
                    SUM(huevos_agregados) AS total_huevos
                FROM historial_compras_lote
                GROUP BY lote_id
            ) h ON h.lote_id = l.id
            SET 
                l.costo_total_acumulado = h.total_costo,
                l.costo_total_lote = h.total_costo,
                l.cantidad_cartones_facturados = h.total_cartones_facturados,
                l.cantidad_cartones_regalo = h.total_cartones_regalo,
                l.cantidad_cartones_recibidos = h.total_cartones_facturados + h.total_cartones_regalo,
                l.huevos_facturados_acumulados = h.total_cartones_facturados * l.huevos_por_carton,
                l.huevos_regalo_acumulados = h.total_cartones_regalo * l.huevos_por_carton,
                l.cantidad_huevos_original = h.total_huevos,
                l.costo_por_carton_facturado = CASE 
                    WHEN h.total_cartones_facturados > 0 
                    THEN ROUND(h.total_costo / h.total_cartones_facturados, 4)
                    ELSE 0 
                END,
                l.costo_por_huevo = CASE 
                    WHEN h.total_cartones_facturados > 0 AND l.huevos_por_carton > 0
                    THEN ROUND(h.total_costo / (h.total_cartones_facturados * l.huevos_por_carton), 4)
                    ELSE 0 
                END
            WHERE l.numero_lote LIKE 'LU-%'
        ");
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->decimal('costo_por_huevo', 12, 2)->nullable()->change();
            $table->decimal('costo_total_lote', 12, 2)->nullable()->change();
        });

        Schema::table('reempaques', function (Blueprint $table) {
            $table->decimal('costo_total', 12, 2)->nullable()->change();
            $table->decimal('costo_unitario_promedio', 12, 2)->nullable()->change();
        });

        Schema::table('reempaque_lotes', function (Blueprint $table) {
            $table->decimal('costo_parcial', 12, 2)->nullable()->change();
        });

        Schema::table('reempaque_productos', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->nullable()->change();
            $table->decimal('costo_total', 12, 2)->nullable()->change();
        });

        Schema::table('camion_producto', function (Blueprint $table) {
            $table->decimal('costo_promedio', 12, 2)->nullable()->change();
        });
    }
};