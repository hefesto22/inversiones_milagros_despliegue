<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega viaje_descargas.procesado_reingreso — marca idempotente del reintegro.
 *
 * Contexto: DescargasRelationManager ya escribía este campo desde la acción
 * manual "Reingresar a Bodega", pero la columna nunca tuvo migración y
 * Viaje::cerrar() no la verificaba, lo que permitía un DOBLE reintegro
 * (manual + cierre). Con el nuevo ReintegroDescargasService ambos caminos
 * respetan y marcan esta bandera.
 *
 * Guardado con hasColumn por si la columna fue agregada a mano en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('viaje_descargas', 'procesado_reingreso')) {
            Schema::table('viaje_descargas', function (Blueprint $table) {
                $table->boolean('procesado_reingreso')
                    ->default(false)
                    ->after('reingresa_stock')
                    ->comment('Reintegro a inventario ya aplicado (evita doble reintegro manual + cierre)');
            });
        }

        // Backfill: las descargas de viajes ya cerrados ya fueron reintegradas
        // durante su cierre — marcarlas evita cualquier reproceso accidental.
        DB::table('viaje_descargas')
            ->whereIn('viaje_id', function ($query) {
                $query->select('id')
                    ->from('viajes')
                    ->where('estado', 'cerrado');
            })
            ->where('reingresa_stock', true)
            ->where('estado_producto', 'bueno')
            ->update(['procesado_reingreso' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('viaje_descargas', 'procesado_reingreso')) {
            Schema::table('viaje_descargas', function (Blueprint $table) {
                $table->dropColumn('procesado_reingreso');
            });
        }
    }
};
