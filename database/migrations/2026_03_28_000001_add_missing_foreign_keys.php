<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega foreign keys faltantes que quedaron como unsignedBigInteger sin constrainted().
 *
 * Razón: La migración create_viaje_ventas_tables tenía extensión .php.php (error tipográfico)
 * y no se ejecutaba correctamente. Además, viaje_comision_detalle tenía viaje_venta_id y
 * viaje_venta_detalle_id sin FK porque las tablas destino no existían al momento de la migración.
 *
 * Idempotencia: cada FK se verifica contra information_schema antes de agregarse, de modo que
 * la migración puede re-ejecutarse o aplicarse sobre una DB que ya fue parcheada sin fallar.
 */
return new class extends Migration
{
    /**
     * Retorna true si la tabla ya tiene una FK con el nombre indicado.
     *
     * Laravel 12 no expone un Schema::hasForeignKey(), así que consultamos
     * information_schema directamente. MySQL 8 y MariaDB 10+ soportan esta vista.
     */
    private function fkExiste(string $tabla, string $fkName): bool
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$tabla, $fkName, 'FOREIGN KEY']
        );

        return !empty($rows);
    }

    public function up(): void
    {
        // =====================================================
        // FK FALTANTE: viaje_ventas.viaje_id → viajes.id
        // =====================================================
        if (Schema::hasColumn('viaje_ventas', 'viaje_id')
            && !$this->fkExiste('viaje_ventas', 'viaje_ventas_viaje_id_foreign')) {
            Schema::table('viaje_ventas', function (Blueprint $table) {
                $table->foreign('viaje_id')
                    ->references('id')
                    ->on('viajes')
                    ->cascadeOnDelete();
            });
        }

        // =====================================================
        // FK FALTANTE: viaje_venta_detalles.viaje_carga_id → viaje_cargas.id
        // =====================================================
        if (Schema::hasColumn('viaje_venta_detalles', 'viaje_carga_id')
            && !$this->fkExiste('viaje_venta_detalles', 'viaje_venta_detalles_viaje_carga_id_foreign')) {
            Schema::table('viaje_venta_detalles', function (Blueprint $table) {
                $table->foreign('viaje_carga_id')
                    ->references('id')
                    ->on('viaje_cargas')
                    ->nullOnDelete();
            });
        }

        // =====================================================
        // FK FALTANTES: viaje_comision_detalle → viaje_ventas y viaje_venta_detalles
        // =====================================================
        if (Schema::hasColumn('viaje_comision_detalle', 'viaje_venta_id')
            && !$this->fkExiste('viaje_comision_detalle', 'viaje_comision_detalle_viaje_venta_id_foreign')) {
            Schema::table('viaje_comision_detalle', function (Blueprint $table) {
                $table->foreign('viaje_venta_id')
                    ->references('id')
                    ->on('viaje_ventas')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('viaje_comision_detalle', 'viaje_venta_detalle_id')
            && !$this->fkExiste('viaje_comision_detalle', 'viaje_comision_detalle_viaje_venta_detalle_id_foreign')) {
            Schema::table('viaje_comision_detalle', function (Blueprint $table) {
                $table->foreign('viaje_venta_detalle_id')
                    ->references('id')
                    ->on('viaje_venta_detalles')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->fkExiste('viaje_comision_detalle', 'viaje_comision_detalle_viaje_venta_detalle_id_foreign')) {
            Schema::table('viaje_comision_detalle', function (Blueprint $table) {
                $table->dropForeign(['viaje_venta_detalle_id']);
            });
        }

        if ($this->fkExiste('viaje_comision_detalle', 'viaje_comision_detalle_viaje_venta_id_foreign')) {
            Schema::table('viaje_comision_detalle', function (Blueprint $table) {
                $table->dropForeign(['viaje_venta_id']);
            });
        }

        if ($this->fkExiste('viaje_venta_detalles', 'viaje_venta_detalles_viaje_carga_id_foreign')) {
            Schema::table('viaje_venta_detalles', function (Blueprint $table) {
                $table->dropForeign(['viaje_carga_id']);
            });
        }

        if ($this->fkExiste('viaje_ventas', 'viaje_ventas_viaje_id_foreign')) {
            Schema::table('viaje_ventas', function (Blueprint $table) {
                $table->dropForeign(['viaje_id']);
            });
        }
    }
};
