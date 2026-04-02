<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agregar 'diesel' como opción al enum tipo_gasto en camion_gastos.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE camion_gastos MODIFY COLUMN tipo_gasto ENUM('gasolina', 'diesel', 'mantenimiento', 'reparacion', 'peaje', 'viaticos', 'lavado', 'otros') NOT NULL COMMENT 'Tipo de gasto'");
    }

    /**
     * Revertir: quitar 'diesel' del enum.
     */
    public function down(): void
    {
        // Solo revertir si no hay registros con 'diesel'
        $count = DB::table('camion_gastos')->where('tipo_gasto', 'diesel')->count();

        if ($count === 0) {
            DB::statement("ALTER TABLE camion_gastos MODIFY COLUMN tipo_gasto ENUM('gasolina', 'mantenimiento', 'reparacion', 'peaje', 'viaticos', 'lavado', 'otros') NOT NULL COMMENT 'Tipo de gasto'");
        }
    }
};
