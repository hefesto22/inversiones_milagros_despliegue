<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chofer_comision_config', function (Blueprint $table) {
            $table->enum('tipo_comision', ['fijo', 'porcentaje'])
                ->default('fijo')
                ->after('unidad_id')
                ->comment('fijo = monto en Lempiras, porcentaje = % sobre precio de venta');
        });
    }

    public function down(): void
    {
        Schema::table('chofer_comision_config', function (Blueprint $table) {
            $table->dropColumn('tipo_comision');
        });
    }
};





