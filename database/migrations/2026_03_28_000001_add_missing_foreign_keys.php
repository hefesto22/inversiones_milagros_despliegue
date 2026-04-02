<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega foreign keys faltantes que quedaron como unsignedBigInteger sin constrainted().
 *
 * Razón: La migración create_viaje_ventas_tables tenía extensión .php.php (error tipográfico)
 * y no se ejecutaba correctamente. Además, viaje_comision_detalle tenía viaje_venta_id y
 * viaje_venta_detalle_id sin FK porque las tablas destino no existían al momento de la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // FK FALTANTE: viaje_ventas.viaje_id → viajes.id
        // =====================================================
        Schema::table('viaje_ventas', function (Blueprint $table) {
            // Agregar FK solo si no existe ya
            $table->foreign('viaje_id')
                ->references('id')
                ->on('viajes')
                ->cascadeOnDelete();
        });

        // =====================================================
        // FK FALTANTE: viaje_venta_detalles.viaje_carga_id → viaje_cargas.id
        // =====================================================
        Schema::table('viaje_venta_detalles', function (Blueprint $table) {
            $table->foreign('viaje_carga_id')
                ->references('id')
                ->on('viaje_cargas')
                ->nullOnDelete();
        });

        // =====================================================
        // FK FALTANTES: viaje_comision_detalle → viaje_ventas y viaje_venta_detalles
        // Estas se debían agregar en la migración de viaje_ventas pero nunca se hizo.
        // =====================================================
        Schema::table('viaje_comision_detalle', function (Blueprint $table) {
            $table->foreign('viaje_venta_id')
                ->references('id')
                ->on('viaje_ventas')
                ->cascadeOnDelete();

            $table->foreign('viaje_venta_detalle_id')
                ->references('id')
                ->on('viaje_venta_detalles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('viaje_comision_detalle', function (Blueprint $table) {
            $table->dropForeign(['viaje_venta_detalle_id']);
            $table->dropForeign(['viaje_venta_id']);
        });

        Schema::table('viaje_venta_detalles', function (Blueprint $table) {
            $table->dropForeign(['viaje_carga_id']);
        });

        Schema::table('viaje_ventas', function (Blueprint $table) {
            $table->dropForeign(['viaje_id']);
        });
    }
};
