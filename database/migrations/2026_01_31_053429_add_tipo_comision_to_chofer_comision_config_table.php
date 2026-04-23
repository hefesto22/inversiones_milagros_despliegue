<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTA HISTÓRICA:
 * La columna `tipo_comision` fue agregada originalmente en esta migración (31-ene-2026).
 * Posteriormente, la migración `2025_11_12_191413_create_camiones_choferes_tables.php` fue
 * editada para incluir la columna directamente en el `Schema::create`, dejando esta migración
 * en estado redundante para entornos que ejecutan `migrate:fresh`.
 *
 * En entornos productivos ya migrados, esta migración está marcada como ejecutada en
 * `migrations` y no corre. En entornos nuevos (tests, staging fresco), la columna ya existe
 * al llegar aquí, por lo que envolvemos la adición en un check `Schema::hasColumn()` para
 * evitar el error `Duplicate column name 'tipo_comision'` sin romper la historia de migraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chofer_comision_config', 'tipo_comision')) {
            return;
        }

        Schema::table('chofer_comision_config', function (Blueprint $table) {
            $table->enum('tipo_comision', ['fijo', 'porcentaje'])
                ->default('fijo')
                ->after('unidad_id')
                ->comment('fijo = monto en Lempiras, porcentaje = % sobre precio de venta');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chofer_comision_config', 'tipo_comision')) {
            return;
        }

        Schema::table('chofer_comision_config', function (Blueprint $table) {
            $table->dropColumn('tipo_comision');
        });
    }
};
